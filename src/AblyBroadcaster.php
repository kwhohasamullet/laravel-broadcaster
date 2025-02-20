<?php

namespace Ably\LaravelBroadcaster;

use Ably\AblyRest;
use Ably\Exceptions\AblyException;
use Ably\Models\Message as AblyMessage;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AblyBroadcaster extends Broadcaster
{
    const LIB_VERSION = '1.0.1';

    /**
     * The AblyRest SDK instance.
     *
     * @var \Ably\AblyRest
     */
    protected $ably;

    /**
     * Used for setting expiry of issues tokens.
     *
     * @var int|mixed
     * @default 1 hr
     */
    private $tokenExpiry = 3600;

    /**
     * Default channel capabilities, all public channels are by default given subscribe, history and channel-metadata access
     * Set as per https://ably.com/docs/core-features/authentication#capability-operations.
     *
     * @var array
     */
    private $defaultChannelClaims = [
        'public:*' => ['subscribe', 'history', 'channel-metadata'],
    ];

    /**
     * Used for storing the difference in seconds between system time and Ably server time
     *
     * @var int
     */
    private $serverTimeDiff;

    /**
     * Create a new broadcaster instance.
     *
     * @param  \Ably\AblyRest  $ably
     * @param  array  $config
     * @return void
     */
    public function __construct(AblyRest $ably, $config)
    {
        $this->ably = $ably;

        // Local file cache is preferred to avoid sharing serverTimeDiff across different servers
        $this->serverTimeDiff = Cache::store('file')->remember('ably_server_time_diff', 6 * 3600, function() {
            return time() - round($this->ably->time() / 1000);
        });

        if (array_key_exists('disable_public_channels', $config) && $config['disable_public_channels']) {
            $this->defaultChannelClaims = ['public:*' => ['channel-metadata']];
        }
        if (array_key_exists('token_expiry', $config)) {
            $this->tokenExpiry = $config['token_expiry'];
        }
    }

    /**
     * @return int
     */
    private function getServerTime()
    {
        if ($this->serverTimeDiff != null) {
            return time() - $this->serverTimeDiff;
        }

        return time();
    }

    /**
     * Get the public token value from the Ably key.
     *
     * @return mixed
     */
    protected function getPublicToken()
    {
        return Str::before($this->ably->options->key, ':');
    }

    /**
     * Get the private token value from the Ably key.
     *
     * @return mixed
     */
    protected function getPrivateToken()
    {
        return Str::after($this->ably->options->key, ':');
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     *
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    public function auth($request)
    {
        $channelName = $request->channel_name;
        $token = $request->token;
        $connectionId = $request->socket_id;
        $normalizedChannelName = $this->normalizeChannelName($channelName);
        $userId = null;
        $channelCapability = ['*'];
        $user = $this->retrieveUser($request, $normalizedChannelName);
        if ($user) {
            $userId = method_exists($user, 'getAuthIdentifierForBroadcasting')
                ? $user->getAuthIdentifierForBroadcasting()
                : $user->getAuthIdentifier();
        }
        if ($this->isGuardedChannel($channelName)) {
            if (! $user) {
                throw new AccessDeniedHttpException('User not authenticated, '.$this->stringify($channelName, $connectionId));
            }
            try {
                $userChannelMetaData = parent::verifyUserCanAccessChannel($request, $normalizedChannelName);
                if (is_array($userChannelMetaData) && array_key_exists('capability', $userChannelMetaData)) {
                    $channelCapability = $userChannelMetaData['capability'];
                    unset($userChannelMetaData['capability']);
                }
            } catch (\Exception $e) {
                throw new AccessDeniedHttpException('Access denied, '.$this->stringify($channelName, $connectionId, $userId), $e);
            }
        }

        try {
            $signedToken = $this->getSignedToken($channelName, $token, $userId, $channelCapability);
        } catch (\Exception $_) { // excluding exception to avoid exposing private key
            throw new AccessDeniedHttpException('malformed token, '.$this->stringify($channelName, $connectionId, $userId));
        }

        $response = ['token' => $signedToken];
        if (isset($userChannelMetaData) && is_array($userChannelMetaData) && count($userChannelMetaData) > 0) {
            $response['info'] = $userChannelMetaData;
        }

        return $response;
    }

    /**
     * Return the valid authentication response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $result
     * @return mixed
     */
    public function validAuthenticationResponse($request, $result)
    {
        return $result;
    }

    /**
     * Broadcast the given event.
     *
     * @param  array  $channels
     * @param  string  $event
     * @param  array  $payload
     * @return void
     *
     * @throws \Illuminate\Broadcasting\BroadcastException
     */
    public function broadcast($channels, $event, $payload = [])
    {
        try {
            foreach ($this->formatChannels($channels) as $channel) {
                $this->ably->channels->get($channel)->publish(
                    $this->buildAblyMessage($event, $payload)
                );
            }
        } catch (AblyException $e) {
            throw new BroadcastException(
                sprintf('Ably error: %s', $e->getMessage())
            );
        }
    }

    /**
     * @param  string  $channelName
     * @param  string  $token
     * @param  string  $clientId
     * @param  string[]  $channelCapability
     * @return string
     */
    public function getSignedToken($channelName, $token, $clientId, $channelCapability = ['*'])
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
            'kid' => $this->getPublicToken(),
        ];
        // Set capabilities for public channel as per https://ably.com/docs/core-features/authentication#capability-operations
        $channelClaims = $this->defaultChannelClaims;
        $serverTimeFn = function () {
            return $this->getServerTime();
        };
        if ($token && Utils::isJwtValid($token, $serverTimeFn, $this->getPrivateToken())) {
            $payload = Utils::parseJwt($token)['payload'];
            $iat = $payload['iat'];
            $exp = $payload['exp'];
            $channelClaims = json_decode($payload['x-ably-capability'], true);
        } else {
            $iat = $serverTimeFn();
            $exp = $iat + $this->tokenExpiry;
        }
        if ($channelName) {
            $channelClaims[$channelName] = $channelCapability;
        }
        $claims = [
            'iat' => $iat,
            'exp' => $exp,
            'x-ably-clientId' => $clientId ? strval($clientId) : null,
            'x-ably-capability' => json_encode($channelClaims),
        ];

        return Utils::generateJwt($header, $claims, $this->getPrivateToken());
    }

    /**
     * Remove prefix from channel name.
     *
     * @param  string  $channel
     * @return string
     */
    public function normalizeChannelName($channel)
    {
        if ($channel) {
            if ($this->isPrivateChannel($channel)) {
                return Str::replaceFirst('private:', '', $channel);
            }
            if ($this->isPresenceChannel($channel)) {
                return Str::replaceFirst('presence:', '', $channel);
            }

            return Str::replaceFirst('public:', '', $channel);
        }

        return $channel;
    }

    /**
     * Checks if channel is a private channel.
     *
     * @param  string  $channel
     * @return bool
     */
    public function isPrivateChannel($channel)
    {
        return Str::startsWith($channel, 'private:');
    }

    /**
     * Checks if channel is a presence channel.
     *
     * @param  string  $channel
     * @return bool
     */
    public function isPresenceChannel($channel)
    {
        return Str::startsWith($channel, 'presence:');
    }

    /**
     * Checks if channel needs authentication.
     *
     * @param  string  $channel
     * @return bool
     */
    public function isGuardedChannel($channel)
    {
        return $this->isPrivateChannel($channel) || $this->isPresenceChannel($channel);
    }

    /**
     * Format the channel array into an array of strings.
     *
     * @param  array  $channels
     * @return array
     */
    public function formatChannels($channels)
    {
        return array_map(function ($channel) {
            $channel = (string) $channel;

            if (Str::startsWith($channel, ['private-', 'presence-'])) {
                return Str::startsWith($channel, 'private-')
                    ? Str::replaceFirst('private-', 'private:', $channel)
                    : Str::replaceFirst('presence-', 'presence:', $channel);
            }

            return 'public:'.$channel;
        }, $channels);
    }

    /**
     * Build an Ably message object for broadcasting.
     *
     * @param  string  $event
     * @param  array  $payload
     * @return \Ably\Models\Message
     */
    protected function buildAblyMessage($event, $payload = [])
    {
        return tap(new AblyMessage, function ($message) use ($event, $payload) {
            $message->name = $event;
            $message->data = $payload;
            $message->connectionKey = data_get($payload, 'socket');
        });
    }

    /**
     * @param  string  $channelName
     * @param  string  $connectionId
     * @param  string|null  $userId
     * @return string
     */
    protected function stringify($channelName, $connectionId, $userId = null)
    {
        $message = 'channel-name:'.$channelName.' ably-connection-id:'.$connectionId;
        if ($userId) {
            return 'user-id:'.$userId.' '.$message;
        }

        return $message;
    }
}
