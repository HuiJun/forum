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

namespace Phosphorum\Controllers;

use Phosphorum\Github\OAuth as GithubOAuth;
use Phosphorum\Github\Users as GithubUsers;
use Phosphorum\Facebook\OAuth as FacebookOAuth;
use Phosphorum\Facebook\Users as FacebookUsers;
use Phosphorum\Models\Users as ForumUsers;
use Phosphorum\Models\Karma;
use Phosphorum\Models\NotificationsBounces;
use Phosphorum\Models\ActivityNotifications;
use Phalcon\Mvc\Controller;
use Phalcon\Mvc\Model;

/**
 * Class SessionController
 *
 * @package Phosphorum\Controllers
 */
class SessionController extends Controller
{

    /**
     * @return \Phalcon\Http\ResponseInterface
     */
    protected function indexRedirect()
    {
        return $this->response->redirect('discussions');
    }

    /**
     * Returns to the discussion
     *
     * @return \Phalcon\Http\ResponseInterface
     */
    protected function discussionsRedirect()
    {
        $referer = $this->request->getHTTPReferer();
        $path    = parse_url($referer, PHP_URL_PATH);
        if ($path) {
            $this->router->handle($path);
            return $this->router->wasMatched() ? $this->response->redirect($path, true) : $this->indexRedirect();
        } else {
            return $this->indexRedirect();
        }
    }

    /**
     * @return \Phalcon\Http\ResponseInterface|void
     */
    public function authorizeAction($oauth_client = '')
    {
        if (!$this->session->get('identity')) {
            $oauth = $this->getOAuth($oauth_client);
            return $oauth->authorize();
        }

        return $this->discussionsRedirect();
    }

    /**
     * @return \Phalcon\Http\ResponseInterface
     */
    public function accessTokenAction()
    {
        $oauth_client = $this->getOAuthConfig();
        $oauth = $this->getOAuth();

        $response = $oauth->accessToken();

        if (is_array($response)) {

            if (isset($response['error'])) {
                $this->flashSession->error(ucwords($oauth_client).': ' . $response['error']);
                return $this->indexRedirect();
            }

            $oauthUser = $this->getOAuthUser($response['access_token']);
            if (!$oauthUser->isValid()) {
                $this->flashSession->error('Invalid '.ucwords($oauth_client).' response. Please try again');
                return $this->indexRedirect();
            }

            /**
             * Edit/Create the user
             */
            $user = ForumUsers::findFirstByAccessToken($response['access_token']);
            if ($user == false) {
                $user = ForumUsers::findFirstByEmail($oauthUser->getEmail());
                if ($user == false) {
                    $user               = new ForumUsers();
                    $user->token_type   = $response['token_type'];
                    $user->access_token = $response['access_token'];
                }
            }

            if ($user->banned == 'Y') {
                $this->flashSession->error('You have been banned from the forum.');
                return $this->indexRedirect();
            }

            //$user = ForumUsers::findFirst();

            /**
             * Update the user information
             */
            $user->name  = $oauthUser->getName();
            $user->login = $oauthUser->getLogin();
            $email       = $oauthUser->getEmail();

            if (is_string($email)) {
                $user->email = $email;
            } else {
                if (is_array($email)) {
                    if (isset($email['email'])) {
                        $user->email = $email['email'];
                    }
                }
            }

            $user->gravatar_id = $oauthUser->getGravatarId();
            if (!$user->gravatar_id) {
                if ($user->email && strpos($user->email, '@') !== false) {
                    $user->gravatar_id = md5(strtolower($user->email));
                }
            }

            $user->increaseKarma(Karma::LOGIN);

            if (!$user->save()) {
                foreach ($user->getMessages() as $message) {
                    $this->flashSession->error((string)$message);
                    return $this->indexRedirect();
                }
            }

            /**
             * Store the user data in session
             */
            $this->session->set('identity', $user->id);
            $this->session->set('identity-name', $user->name);
            $this->session->set('identity-gravatar', $user->gravatar_id);
            $this->session->set('identity-timezone', $user->timezone);
            $this->session->set('identity-theme', $user->theme);
            $this->session->set('identity-moderator', $user->moderator);

            if ($user->getOperationMade() == Model::OP_CREATE) {
                $this->flashSession->success('Welcome ' . $user->name);
            } else {
                $this->flashSession->success('Welcome back ' . $user->name);
            }

            if ($user->email && strpos($user->email, '@') !== false) {

                if (strpos($user->email, '@users.noreply.github.com') !== false) {
                    $messageNotAlllow = 'Your current e-mail: ' . $this->escaper->escapeHtml($user->email)
                        . ' does not allow us to send you e-mail notifications';
                    $this->flashSession->notice($messageNotAlllow);
                }
            } else {

                $messageCantSend
                    = 'We weren\'t able to obtain your e-mail address'
                    . ' from Github, we can\'t send you e-mail notifications';
                $this->flashSession->notice($messageCantSend);
            }

            if ($user->getOperationMade() != Model::OP_CREATE) {

                /**
                 * Show a notification to users that have e-mail bounces
                 */
                $parametersBounces = array(
                    'email = ?0 AND reported = "N"',
                    'bind' => array($user->email)
                );
                $bounces           = NotificationsBounces::find($parametersBounces);
                if (count($bounces)) {

                    foreach ($bounces as $bounce) {
                        $bounce->reported = 'Y';
                        $bounce->save();
                    }

                    $messageFailid
                        = 'We have failed to deliver you some email notifications,'
                        . ' this might be caused by an invalid email associated to your Github account or '
                        . 'its mail server is rejecting our emails. Your current e-mail is: '
                        . $this->escaper->escapeHtml($user->email);

                    $this->flashSession->notice($messageFailid);

                    $parametersBouncesMax = array(
                        'email = ?0 AND created_at >= ?1',
                        'bind' => array($user->email, time() - 86400 * 7)
                    );

                    $bounces = NotificationsBounces::find($parametersBouncesMax);

                    if (count($bounces) >= NotificationsBounces::MAX_BOUNCES) {
                        $messageRepeat
                            = 'Due to a repeated number of email bounces we have disabled email '
                            . 'notifications for your email. You can re-enable them in your settings';
                        $this->flashSession->notice($messageRepeat);
                        $user->notifications = 'N';
                        $user->save();
                    }
                }

                /**
                 * Show a notification to users that haven't spend their votes
                 */
                if ($user->votes >= 10 && mt_rand(1, 5) == 3) {
                    $this->flashSession->notice('You have ' . $user->votes . ' votes remaining to spend. If you find something useful in this forum do not hesitate to give others some votes.');
                }
            }

            return $this->discussionsRedirect();
        }

        $this->flashSession->error('Invalid '.ucfirst($this->getOAuthConfig()).' response. Please try again');
        return $this->discussionsRedirect();
    }

    private function getOAuth($oauth_client = '') {
        $oauth_client = $this->getOAuthConfig($oauth_client);

        switch($oauth_client) {
            case 'github':
                return new GithubOAuth($this->config->$oauth_client);
            case 'facebook':
                return new FacebookOAuth($this->config->$oauth_client);
            default:
                return false;
        }
    }

    private function getOAuthUser($access_token, $oauth_client = '') {
        switch($this->getOAuthConfig($oauth_client)) {
            case 'github':
                return new GithubUsers($access_token);
            case 'facebook':
                return new FacebookUsers($access_token);
            default:
                return false;
        }
    }

    private function getOAuthConfig($oauth_client) {
        if(!$oauth_client)
            return $this->config->oauth_client['default'];
        else
            return $oauth_client;
    }

    /**
     * @return \Phalcon\Http\ResponseInterface
     */
    public function logoutAction()
    {
        $this->session->remove('identity');
        $this->session->remove('identity-name');
        $this->session->remove('identity-moderator');
        $this->session->remove('identity-gravatar');
        $this->session->remove('identity-theme');

        $this->flashSession->success('Goodbye!');
        return $this->discussionsRedirect();
    }
}
