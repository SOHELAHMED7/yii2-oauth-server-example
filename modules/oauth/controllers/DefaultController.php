<?php

namespace app\modules\oauth\controllers;

use GuzzleHttp\Psr7\Stream;
use app\modules\oauth\oauth\entities\AccessTokenEntity;
use yii\helpers\Json;
use app\modules\oauth\oauth\repositories\ScopeRepository;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use app\modules\oauth\oauth\entities\UserEntity;
use app\modules\oauth\oauth\AuthorizationServer as AppAuthorizationServer;
use app\components\psr7\Request;
use app\components\psr7\Response;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;

class DefaultController extends Controller
{
    public $enableCsrfValidation = false; // TODO only do this where needed, not in all actions

    public function behaviors()
    {
        return [
            'accessControl' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                    [
                        'actions' => ['authorize', 'my-access-token'],
                        'allow' => true,
                        'roles' => ['?'],
                    ]
                ],
            ]
        ];
    }

    public function actionIndex()
    {
        $accessToken = AccessTokenEntity::find()->andWhere([
            'user_id' => Yii::$app->user->identity->id,
            'is_revoked' => 0,
        ])->andWhere(
            ['>=', 'expires_at', date('Y-m-d H:i:s')]
        )->one();

        return $this->render('index', ['accessToken' => $accessToken]);
    }

    /**
     * Example implementation of Auth Grant
     * @see https://oauth2.thephpleague.com/authorization-server/auth-code-grant/ headline "Implementation" adjusted for yii
     *
     * /authorize
     */
    public function actionAuthorize()
    {
        $server = AppAuthorizationServer::getInstance();
        /** @var Psr\Http\Message\ServerRequestInterface  */
        $request = Yii::$app->request->getPsr7Request();
        $response = new \Laminas\Diactoros\Response();

        /** @var AuthorizationRequest|null  */
        $authRequest = Yii::$app->getSession()->get('auth_req');
        parse_str($request->getUri()->getQuery(), $queryParams);

        try {
            // when user just logged in from login form
            if ($authRequest instanceof AuthorizationRequest &&
                !empty($queryParams['is_logged_in']) &&
                $queryParams['is_logged_in'] === 'yes' &&
                !Yii::$app->user->isGuest
            ) {
                // if the user once approved (allowed) the server and any one access token is not revoked nor expired, then now no need to redirect user to allow-deny page
                return $this->handleAuthUser($authRequest, $server, $response);

                // when user clicked 'Allow'
            } elseif ($authRequest instanceof AuthorizationRequest &&
                !empty($queryParams['result']) && !Yii::$app->user->isGuest) {
                $authRequest->setUser(UserEntity::findIdentity(Yii::$app->user->identity->id));
                if ($queryParams['result'] === 'yes') { // 1
                    $authRequest->setAuthorizationApproved(true);
                } else { // 0
                    $authRequest->setAuthorizationApproved(false);
                }
                Yii::$app->getSession()->set('auth_req', $authRequest);
                return \Yii::$app->response->mergeWithPsr7Response(
                    $server->completeAuthorizationRequest($authRequest, $response)
                );

                // when user is already logged in on server when redirected by client to server to authenticate
            } elseif (!Yii::$app->user->isGuest) {
                unset($authRequest);
                $authRequest = $this->initialStep($server, $request);
                return $this->handleAuthUser($authRequest, $server, $response);
            }

            // The very first step in a typical scenario
            unset($authRequest);
            $authRequest = $this->initialStep($server, $request);
            Yii::$app->getSession()->set('auth_req', $authRequest);
            return $this->redirect(['/user/security/login']);

        } catch (OAuthServerException $exception) {
            return \Yii::$app->response->mergeWithPsr7Response($exception->generateHttpResponse($response));

        } catch (\Exception $exception) {

            $body = new Stream(fopen('php://temp', 'r+'));
            $body->write($exception->getMessage());
            Yii::$app->response->statusCode = 500;
            return $response->withBody($body)->getBody()->__toString();
        }
    }

    // Response is sent in JSON format only
    public function actionMyAccessToken()
    {
        $server = AppAuthorizationServer::getInstance();
        $request = Yii::$app->request->getPsr7Request();
        $response = new \Laminas\Diactoros\Response();
        $yiiResponse = Yii::$app->response;
        $yiiResponse->format = \yii\web\Response::FORMAT_JSON;

        try {
            $response = $server->respondToAccessTokenRequest($request, $response);
            $yiiResponse->content = $response->getBody()->__toString();
            return $yiiResponse;
        } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {
            $yiiResponse->statusCode = 500;
            $yiiResponse->content = $exception->generateHttpResponse($response)->getBody()->__toString();
            return $yiiResponse;

        } catch (\Exception $exception) {
            $body = new Stream(fopen('php://temp', 'r+'));
            $body->write($exception->getMessage());
            $yiiResponse->statusCode = 500;
            $yiiResponse->content = Json::encode(['error' => $response->withBody($body)->getBody()->__toString()]);
            return $yiiResponse;
        }
    }

    public function actionAllowDenyAccess()
    {
        $authRequest = Yii::$app->getSession()->get('auth_req');
        if (!$authRequest instanceof AuthorizationRequest) {
            throw new Exception("Auth Request is not set in session");
        }

        $sr = new ScopeRepository;

        $scopeDesc = [];

        if (!empty($authRequest->getScopes()[0])) {
            foreach ($authRequest->getScopes() as $key => $value) {
                $scopeDesc[] = $sr->allScopes[$authRequest->getScopes()[$key]->getIdentifier()]['description'];
            }
        }

        return $this->render('allow-deny-access', ['scopeDesc' => $scopeDesc]);
    }

    /**
     * Handle Default Scope
     * @param  AuthorizationRequest $authRequest
     * @return void
     */
    protected function handleDefaultScope($authRequest)
    {
        if (!$authRequest->getScopes()) {
            $authRequest->setScopes([(new ScopeRepository())->getScopeEntityByIdentifier('email')]);
        }
    }

    /**
     * Handle Approved
     * @param  AuthorizationRequest $authRequest
     * @param  AppAuthorizationServer $server
     * @param  \Laminas\Diactoros\Response $response
     * @return \app\components\psr7\Response
     */
    protected function handleApproved($authRequest, $server, $response)
    {
        $authRequest->setAuthorizationApproved(true);
        Yii::$app->getSession()->set('auth_req', $authRequest);
        return \Yii::$app->response->mergeWithPsr7Response(
            $server->completeAuthorizationRequest($authRequest, $response)
        );
    }

    /**
     * Initial Step of validation
     * @param  AppAuthorizationServer $server
     * @param  Psr\Http\Message\ServerRequestInterface $request
     * @return AuthorizationRequest
     */
    protected function initialStep($server, $request)
    {
        $newAuthRequest = $server->validateAuthorizationRequest($request);
        // when scope is present in request query string but empty, whitespace, 0 or having similar values, set default to email
        $this->handleDefaultScope($newAuthRequest);
        return $newAuthRequest;
    }

    /**
     * Handle Authenticated User
     * @param  AuthorizationRequest $authRequest
     * @param  AppAuthorizationServer $server
     * @param  \Laminas\Diactoros\Response $response
     * @return \yii\web\Response
     */
    protected function handleAuthUser($authRequest, $server, $response)
    {
        $authRequest->setUser(UserEntity::findIdentity(Yii::$app->user->identity->id));
        // if the user once approved (allowed) the server and any one access token is not revoked nor expired, then now no need to redirect user to allow-deny page
        if (AccessTokenEntity::checkToken(Yii::$app->user->identity->id, $authRequest->getClient(), $authRequest->getScopes())) {
            return $this->handleApproved($authRequest, $server, $response);
        }

        Yii::$app->getSession()->set('auth_req', $authRequest);
        return $this->redirect(['allow-deny-access']);
    }

    /**
     * Action Revoke
     * @param  int|string $clientId
     * @return \yii\web\Response
     */
    public function actionRevoke($clientId)
    {
        $accessTokens = AccessTokenEntity::find()->andWhere([
            'user_id' => Yii::$app->user->identity->id,
            'oauth_client_id' => $clientId,
            'is_revoked' => 0,
        ])->andWhere(
            ['>=', 'expires_at', date('Y-m-d H:i:s')]
        )->all();

        foreach ($accessTokens as $key => $token) {
            $token->updateAttributes(['is_revoked' => 1]);
        }
        return $this->redirect(['/oauth/default/index']);
    }
}
