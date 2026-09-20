<?php

declare(strict_types=1);

namespace IndieAuthRocks\Checkup;

use IndieAuthRocks\Checkup\Steps\DiscoverySteps;
use IndieAuthRocks\Checkup\Steps\RedemptionSteps;
use IndieAuthRocks\Checkup\Steps\RequestSteps;
use IndieAuthRocks\Checkup\Steps\ResponseSteps;
use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Crypto\Random;
use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\Fetch\RecordingHttpClient;
use IndieAuthRocks\IndieAuth\Discovery;
use IndieAuthRocks\IndieAuth\MeVerifier;
use IndieAuthRocks\IndieAuth\Pkce;
use IndieAuthRocks\ServerTests\TokenClient;
use IndieAuthRocks\Storage\RedisStore;
use IndieAuthRocks\Storage\RunRepository;

/**
 * One login, walked through and written down.
 *
 * The run row holds what can be shown; Redis holds what must not outlive the
 * login — the PKCE verifier, and the fact that this browser is the one waiting
 * for a reply. No token is ever stored: it is described, revoked if the server
 * offers a way, and dropped.
 */
final class Walkthrough
{
    private const PENDING_TTL = 900;
    /** Only ever this: a checkup is a diagnosis, not a way to mint credentials. */
    private const SCOPE = 'profile';

    public function __construct(
        private readonly SiteConfig $config,
        private readonly RecordingHttpClient $http,
        private readonly RunRepository $runs,
        private readonly RedisStore $redis,
    ) {
    }

    /**
     * Fetch the person's site, work out what can be said about it, and keep the
     * result. The run is created first so its HTTP is recorded against it.
     */
    public function begin(string $typed, bool $wantsToken, string $ownerSecret): Checkup
    {
        $profileUrl = Discovery::normalizeInput(trim($typed));

        $run = $this->runs->create(
            kind:      Checkup::KIND,
            targetUrl: $profileUrl,
            mode:      'browser',
            options:   ['wants_token' => $wantsToken],
            state:     ['typed' => trim($typed), 'phase' => Checkup::DISCOVERED, 'owner_hash' => hash('sha256', $ownerSecret)],
            testerId:  null,
            ttlDays:   $this->config->checkupRetentionDays,
        );

        $this->http->context($run->id, 'discovery');
        $discovery = (new Discovery($this->http))->discover($profileUrl);
        $this->http->context(null);

        $steps = DiscoverySteps::from($discovery, trim($typed));

        $state = $run->state;
        $state['steps']     = Step::listToArray($steps);
        $state['discovery'] = $discovery->toArray();
        $state['endpoints'] = [
            'authorization' => $discovery->authorizationEndpoint(),
            'token'         => $discovery->tokenEndpoint(),
            'issuer'        => $discovery->string('issuer'),
        ];
        $state['discovery_urls'] = $discovery->discoveryUrls();

        $this->runs->save($run, $state, Run::RUNNING);

        return new Checkup($run);
    }

    /**
     * Mint a request, remember what has to be checked when it comes back, and
     * return where to send the browser.
     *
     * @return array{url: string, params: array<string, string>}
     */
    public function depart(Checkup $checkup, string $sessionId): array
    {
        $endpoint = (string) $checkup->endpoint('authorization');
        $state    = Random::token(24);
        $verifier = Random::token(48);

        $params = [
            'response_type'         => 'code',
            'client_id'             => $this->clientId(),
            'redirect_uri'          => $this->callbackUrl(),
            'state'                 => $state,
            'code_challenge'        => Pkce::challengeFor($verifier),
            'code_challenge_method' => 'S256',
            'me'                    => $checkup->profileUrl(),
        ];

        if ($checkup->wantsToken()) {
            $params['scope'] = self::SCOPE;
        }

        $url = $endpoint . (str_contains($endpoint, '?') ? '&' : '?')
            . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $this->redis->setJson(self::pendingKey($state), [
            'run'      => $checkup->run->id,
            'session'  => hash('sha256', $sessionId),
            'verifier' => $verifier,
            'sent_at'  => time(),
        ], self::PENDING_TTL);

        $runState                   = $checkup->run->state;
        $runState['phase']          = Checkup::SENT;
        $runState['state_sent']     = $state;
        $runState['steps']          = Step::listToArray([...$checkup->steps(), RequestSteps::pending($url, $params)]);
        $this->runs->save($checkup->run, $runState, Run::WAITING);

        return ['url' => $url, 'params' => $params];
    }

    /**
     * The pending record for a reply, if this browser is the one waiting for it.
     *
     * @return array<string, mixed>|null
     */
    public function pending(string $state, string $sessionId): ?array
    {
        if ($state === '') {
            return null;
        }

        $pending = $this->redis->takeJson(self::pendingKey($state));

        if ($pending === null || !hash_equals((string) ($pending['session'] ?? ''), hash('sha256', $sessionId))) {
            return null;
        }

        return $pending;
    }

    /**
     * Grade the reply, exchange the code if there is one worth exchanging, and
     * finish the checkup.
     *
     * @param array<string, string> $query
     * @param array<string, mixed>  $pending
     */
    public function finish(Checkup $checkup, array $query, array $pending): void
    {
        $run    = $checkup->run;
        $state  = $run->state;
        $sentAt = is_int($pending['sent_at'] ?? null) ? $pending['sent_at'] : null;

        $steps = $checkup->steps();

        // The request step stops being a preview once they have used it.
        foreach ($steps as $i => $step) {
            if ($step->id === 'request') {
                $steps[$i] = RequestSteps::sent($step);
            }
        }

        $steps = [...$steps, ...ResponseSteps::from(
            $query,
            (string) ($state['state_sent'] ?? ''),
            $checkup->endpoint('issuer'),
            $sentAt === null ? null : time() - $sentAt,
        )];

        $code = $query['code'] ?? null;
        $stop = false;

        foreach ($steps as $step) {
            $stop = $stop || $step->state->stopsTheLogin();
        }

        if (!$stop && is_string($code) && $code !== '') {
            $steps = [...$steps, ...$this->redeem($checkup, $code, (string) $pending['verifier'])];
        }

        $state['phase'] = Checkup::FINISHED;
        $state['steps'] = Step::listToArray($steps);
        $state['me']    = $this->meFrom($steps);

        $this->runs->save($run, $state, Run::COMPLETE);
    }

    /** They never came back, or came back in a way we cannot tie to a request. */
    public function abandon(Checkup $checkup): void
    {
        $steps = [];

        foreach ($checkup->steps() as $step) {
            $steps[] = $step->id === 'request' && $step->state === StepState::Pending
                ? RequestSteps::waiting($step)
                : $step;
        }

        $state          = $checkup->run->state;
        $state['steps'] = Step::listToArray($steps);

        $this->runs->save($checkup->run, $state);
    }

    /** @return list<Step> */
    private function redeem(Checkup $checkup, string $code, string $verifier): array
    {
        // A checkup with no scope redeems at the authorization endpoint, which
        // answers with who you are and issues nothing. Asking to see the token
        // response is what moves it to the token endpoint.
        $endpoint = $checkup->wantsToken()
            ? (string) ($checkup->endpoint('token') ?? $checkup->endpoint('authorization'))
            : (string) $checkup->endpoint('authorization');

        $this->http->context($checkup->run->id, 'redeem');
        $reply = (new TokenClient($this->http))->redeem($endpoint, $code, $this->clientId(), $this->callbackUrl(), $verifier);

        $me       = $reply->string('me');
        $verified = null;

        if ($me !== null) {
            $this->http->context($checkup->run->id, 'verify-me');
            /** @var list<string> $discoveryUrls */
            $discoveryUrls = is_array($checkup->run->state['discovery_urls'] ?? null) ? $checkup->run->state['discovery_urls'] : [];
            $verified      = (new MeVerifier(new Discovery($this->http)))->verify(
                $me,
                $discoveryUrls,
                (string) $checkup->endpoint('authorization'),
            );
        }

        $steps = RedemptionSteps::from($reply, $endpoint, $checkup->profileUrl(), $checkup->wantsToken(), $verified);

        // Nothing here needs the token afterwards, so hand it back if we can.
        $token = $reply->string('access_token');

        if ($token !== null) {
            $steps[] = $this->revoke($checkup, $token);
        }

        $this->http->context(null);

        return array_values(array_filter($steps));
    }

    private function revoke(Checkup $checkup, string $token): Step
    {
        $endpoint = null;
        $metadata = $checkup->run->state['discovery']['metadata'] ?? null;

        if (is_array($metadata) && is_string($metadata['revocation_endpoint'] ?? null)) {
            $endpoint = $metadata['revocation_endpoint'];
        }

        if ($endpoint === null) {
            return new Step(
                id: 'revoke',
                title: 'Tidying up',
                state: StepState::Note,
                headline: 'The access token your server issued has been thrown away.',
                meaning: 'Your server does not advertise anywhere to cancel a token, so it will stay valid until '
                    . 'it expires. Nothing here keeps a copy of it.',
            );
        }

        $this->http->context($checkup->run->id, 'revoke');
        $reply = (new TokenClient($this->http))->revoke($endpoint, $token);

        return new Step(
            id: 'revoke',
            title: 'Tidying up',
            state: StepState::Ok,
            headline: $reply->isSuccess()
                ? 'The access token your server issued has been cancelled again.'
                : 'The access token was thrown away, though your server did not confirm cancelling it.',
            facts: ['Where tokens are cancelled' => $endpoint, 'Answer' => $reply->describe()],
            urls: [$endpoint],
        );
    }

    /** @param list<Step> $steps */
    private function meFrom(array $steps): ?string
    {
        foreach ($steps as $step) {
            if ($step->id === 'identity' && isset($step->facts['Your server says you are'])) {
                return $step->facts['Your server says you are'];
            }
        }

        return null;
    }

    public function clientId(): string
    {
        return $this->config->url('check/client');
    }

    public function callbackUrl(): string
    {
        return $this->config->url('check/callback');
    }

    private static function pendingKey(string $state): string
    {
        return 'checkup:' . hash('sha256', $state);
    }
}
