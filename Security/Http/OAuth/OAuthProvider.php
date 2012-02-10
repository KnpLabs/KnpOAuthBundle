<?php

/*
 * This file is part of the KnpOAuthBundle package.
 *
 * (c) KnpLabs <hello@knplabs.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Knp\Bundle\OAuthBundle\Security\Http\OAuth;

use Buzz\Client\ClientInterface as HttpClientInterface,
    Buzz\Message\Request as HttpRequest,
    Buzz\Message\Response as HttpResponse;

use Symfony\Component\Security\Core\Exception\AuthenticationException,
    Symfony\Component\Security\Http\HttpUtils,
    Symfony\Component\HttpFoundation\Request;

use Knp\Bundle\OAuthBundle\Security\Http\OAuth\OAuthProviderInterface;

/**
 * OAuthProvider
 *
 * @author Geoffrey Bachelet <geoffrey.bachelet@gmail.com>
 */
class OAuthProvider implements OAuthProviderInterface
{
    /**
     * @var array
     */
    protected $options = array();

    /**
     * @var Buzz\Client\ClientInterface
     */
    protected $httpClient;

    /**
     * @param Buzz\Client\ClientInterface $httpClient
     * @param array                       $options
     */
    public function __construct(HttpClientInterface $httpClient, HttpUtils $httpUtils, array $options)
    {
        if (null !== $options['infos_url'] && null === $options['username_path']) {
            throw new \InvalidArgumentException('You must set an "username_path" to use an "infos_url"');
        }

        if (null === $options['infos_url'] && null !== $options['username_path']) {
            throw new \InvalidArgumentException('You must set an "infos_url" to use an "username_path"');
        }

        /**
         * We want to merge passed options within existing options
         * but only if they are not null. This is a bit messy. Sorry.
         */
        foreach ($options as $k => $v) {
            if (null === $v && array_key_exists($k, $this->options)) {
                unset($options[$k]);
            }
        }

        $this->options    = array_merge($this->options, $options);
        $this->httpClient = $httpClient;
        $this->httpUtils  = $httpUtils;

        $this->configure();
    }

    /**
     * Gives a chance for extending providers to customize stuff
     */
    public function configure()
    {

    }

    /**
     * @param Symfony\Component\HttpFoundation\Request $request
     * @return string
     */
    public function getRedirectUri(Request $request)
    {
        return $this->httpUtils->createRequest($request, $this->getOption('check_path'))->getUri();
    }

    /**
     * Retrieve an option by name
     *
     * @throws InvalidArgumentException When the option does not exist
     * @param string                    $name The option name
     * @return mixed                    The option value
     */
    public function getOption($name)
    {
        if (!array_key_exists($name, $this->options)) {
            throw new \InvalidArgumentException(sprintf('Unknown option "%s"', $name));
        }

        return $this->options[$name];
    }

    /**
     * Performs an HTTP request
     *
     * @param string $url The url to fetch
     * @param string $method The HTTP method to use
     * @return string The response content
     */
    protected function httpRequest($url, $content = null, $method = null)
    {
        if (null === $method) {
            $method = null === $content ? HttpRequest::METHOD_GET : HttpRequest::METHOD_POST;
        }

        $request  = new HttpRequest($method, $url);
        $response = new HttpResponse();

        $request->setContent($content);

        $this->httpClient->send($request, $response);

        return $response->getContent();
    }

    /**
     * {@inheritDoc}
     */
    public function getUsername($accessToken)
    {
        if ($this->getOption('infos_url') === null) {
            return $accessToken;
        }

        $url = $this->getOption('infos_url').'?'.http_build_query(array(
            'access_token' => $accessToken
        ));

        $userInfos    = json_decode($this->httpRequest($url), true);
        $usernamePath = explode('.', $this->getOption('username_path'));

        $username     = $userInfos;

        foreach ($usernamePath as $path) {
            if (!array_key_exists($path, $username)) {
                throw new AuthenticationException(sprintf('Could not follow username path "%s" in OAuth provider response: %s', $this->getOption('username_path'), var_export($userInfos, true)));
            }
            $username = $username[$path];
        }

        return $username;
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthorizationUrl(Request $request, array $extraParameters = array())
    {
        $parameters = array_merge($extraParameters, array(
            'response_type' => 'code',
            'client_id'     => $this->getOption('client_id'),
            'scope'         => $this->getOption('scope'),
            'redirect_uri'  => $this->getRedirectUri($request),
        ));

        return $this->getOption('authorization_url').'?'.http_build_query($parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function getAccessToken(Request $request, array $extraParameters = array())
    {
        $parameters = array_merge($extraParameters, array(
            'code'          => $request->get('code'),
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->getOption('client_id'),
            'client_secret' => $this->getOption('secret'),
            'redirect_uri'  => $this->getRedirectUri($request),
        ));

        $url = $this->getOption('access_token_url').'?'.http_build_query($parameters);

        $response = array();

        parse_str($this->httpRequest($url), $response);

        if (isset($response['error'])) {
            throw new AuthenticationException(sprintf('OAuth error: "%s"', $response['error']));
        }

        return $response['access_token'];
    }
}
