<?php

/*
 +------------------------------------------------------------------------+
 | Phosphorum                                                             |
 +------------------------------------------------------------------------+
 | Copyright (c) 2013-2014 Phalcon Team and contributors                  |
 +------------------------------------------------------------------------+
 | This source file is subject to the New BSD License that is bundled     |
 | with this package in the file docs/LICENSE.txt.                        |
 |                                                                        |
 | If you did not receive a copy of the license and are unable to         |
 | obtain it through the world-wide-web, please send an email             |
 | to license@phalconphp.com so we can send you a copy immediately.       |
 +------------------------------------------------------------------------+
*/

namespace Phosphorum\Facebook;

use Phalcon\DI\Injectable;
use Guzzle\Http\Client as HttpClient;

/**
 * Class OAuth
 *
 * @package Phosphorum\Facebook
 */
class OAuth extends Injectable
{

    protected $endPointAuthorize = 'https://www.facebook.com/dialog/oauth';

    protected $endPointAccessToken = 'https://graph.facebook.com/oauth/access_token';

    protected $scope;

    protected $redirectUriAuthorize;

    protected $baseUri;

    protected $clientId;

    protected $clientSecret;

    protected $transport;

    /**
     * @param $config
     */
    public function __construct($config)
    {
        $this->redirectUriAuthorize = $config->redirectUri;
        $this->clientId             = $config->clientId;
        $this->clientSecret         = $config->clientSecret;
        $this->scope                = isset($config->scope) ? $config->scope : array('email','public_profile');
    }

    /**
     *
     */
    public function authorize()
    {
        $this->view->disable();

        $scope = is_array($this->scope) ? implode(',',$this->scope) : '';

        $url = $this->endPointAuthorize . '?client_id=' . $this->clientId . '&redirect_uri='
            . $this->redirectUriAuthorize
            . // add the tokenkey as a query param. Then we will be able to use it to check token authenticity
            '&scope='.$scope;

        $this->response->redirect($url, true);
    }

    /**
     * @return bool|mixed
     */
    public function accessToken()
    {
        $this->view->disable();

        // check the securtity - anti csrf token
        if ($this->request->getQuery('error')) {
            return false;
        }

        $code   = $this->request->getQuery('code');

        $params   = array(
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUriAuthorize,
            'code'          => $code,
        );

        $response = $this->send($this->endPointAccessToken, $params);

        return $response;
    }

    /**
     * @param        $url
     * @param        $parameters
     * @param string $method
     *
     * @return bool|mixed
     */
    public function send($url, $parameters, $method = 'post')
    {
        try {

            $client = new HttpClient();

            $headers = array(
                'Accept' => 'application/json'
            );

            switch ($method) {
                case 'post':
                    $request = $client->post($url, $headers, $parameters);
                    break;
                case 'get':
                    $request = $client->get($url, $headers, $parameters);
                    break;
                default:
                    throw new \Exception('Invalid HTTP method');
            }

            return json_decode((string)$request->send()->getBody(), true);

        } catch (\Exception $e) {
            //file_put_contents('error.txt', $e->getMessage());
            return false;
        }
    }
}
