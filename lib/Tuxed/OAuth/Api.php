<?php

namespace Tuxed\OAuth;

use \Tuxed\Http\HttpRequest as HttpRequest;
use \Tuxed\Http\HttpResponse as HttpResponse;
use \Tuxed\Logger as Logger;
use \Tuxed\Config as Config;

class Api {

    private $_config;
    private $_storage;

    private $_rs;

    public function __construct(Config $c, Logger $l = NULL) {
        $this->_config = $c;
        $this->_logger = $l;  
        
        $oauthStorageBackend = '\\Tuxed\OAuth\\' . $this->_config->getValue('storageBackend');
        $this->_storage = new $oauthStorageBackend($this->_config);

        if(!$this->_config->getSectionValue("Api", "enableApi")) {
            throw new ApiException("forbidden","api disabled");
        }

        $this->_rs = new ResourceServer($this->_storage);
    }

    public function handleRequest(HttpRequest $request) {
        $response = new HttpResponse();
        $response->setContentType("application/json");

        try { 
            $this->_rs->verifyAuthorizationHeader($request->getHeader("HTTP_AUTHORIZATION"));

            $storage = $this->_storage; // FIXME: can this be avoided??
            $rs = $this->_rs; // FIXME: can this be avoided?? 
           
            $request->matchRest("GET", "/resource_owner/id", function() use ($response, $rs) {
                $response->setContent(json_encode(array("id" => $rs->getResourceOwnerId())));
            });

            $request->matchRest("GET", "/resource_owner/entitlement", function() use ($response, $rs) {
                $response->setContent(json_encode(array("entitlement" => $rs->getEntitlement())));
            });

            $request->matchRest("POST", "/authorizations/", function() use ($request, $response, $storage, $rs) {
                $rs->requireScope("authorizations");
                $data = json_decode($request->getContent(), TRUE);
                if(NULL === $data || !is_array($data) || !array_key_exists("client_id", $data) || !array_key_exists("scope", $data)) {
                    throw new ApiException("invalid_request", "missing required parameters");
                }

                // client needs to exist
                $clientId = $data['client_id'];
                $client = $storage->getClient($clientId);
                if(FALSE === $client) {
                    throw new ApiException("invalid_request", "client is not registered");
                }

                // scope should be part of "allowed_scope" of client registration
                $clientAllowedScope = new Scope($client->allowed_scope);
                $requestedScope = new Scope($data['scope']);
                if(!$requestedScope->isSubSetOf($clientAllowedScope)) {
                    throw new ApiException("invalid_request", "invalid scope for this client");
                }
                $refreshToken = (array_key_exists("refresh_token", $data) && $data['refresh_token']) ? AuthorizationServer::randomHex(16) : NULL;

                // check to see if an authorization for this client/resource_owner already exists
                if(FALSE === $storage->getApproval($clientId, $rs->getResourceOwnerId())) {
                    if(FALSE === $storage->addApproval($clientId, $rs->getResourceOwnerId(), $data['scope'], $refreshToken)) {
                        throw new ApiException("invalid_request", "unable to add authorization");
                    }
                } else {
                    throw new ApiException("invalid_request", "authorization already exists for this client and resource owner");
                }
                $response->setStatusCode(201);
            });

            $request->matchRest("GET", "/authorizations/:id", function($id) use ($request, $response, $storage, $rs) {
                $rs->requireScope("authorizations");
                $data = $storage->getApproval($id, $rs->getResourceOwnerId());
                if(FALSE === $data) {
                    throw new ApiException("not_found", "the resource you are trying to retrieve does not exist");
                }
                $response->setContent(json_encode($data));      
            });

            $request->matchRest("GET", "/authorizations/:id", function($id) use ($request, $response, $storage, $rs) {
                $rs->requireScope("authorizations");
                $data = $storage->getApproval($id, $rs->getResourceOwnerId());
                if(FALSE === $data) {
                    throw new ApiException("not_found", "the resource you are trying to retrieve does not exist");
                }
                $response->setContent(json_encode($data));      
            });

            $request->matchRest("DELETE", "/authorizations/:id", function($id) use ($request, $response, $storage, $rs) {
                $rs->requireScope("authorizations");
                if(FALSE === $storage->deleteApproval($id, $rs->getResourceOwnerId())) {
                    throw new ApiException("not_found", "the resource you are trying to delete does not exist");
                }
            });

            $request->matchRest("GET", "/authorizations/", function() use ($request, $response, $storage, $rs) {
                $rs->requireScope("authorizations");
                $data = $storage->getApprovals($rs->getResourceOwnerId());
                $response->setContent(json_encode($data));
            });

            $request->matchRest("GET", "/applications/", function() use ($request, $response, $storage, $rs) {
                $rs->requireScope("applications");
                // $rs->requireEntitlement("applications");    // do not require entitlement to list clients...
                $data = $storage->getClients();
                $response->setContent(json_encode($data)); 
            });

            $request->matchRest("DELETE", "/applications/:id", function($id) use ($request, $response, $storage, $rs) {
                $rs->requireScope("applications");
                $rs->requireEntitlement("applications");
                if(FALSE === $storage->deleteClient($id)) {
                    throw new ApiException("not_found", "the resource you are trying to delete does not exist");
                }
            });

            $request->matchRest("GET", "/applications/:id", function($id) use ($request, $response, $storage, $rs) {
                $rs->requireScope("applications");
                $rs->requireEntitlement("applications");    // FIXME: for now require entitlement as long as password hashing is not
                                                            // implemented...
                $data = $storage->getClient($id);
                if(FALSE === $data) {
                    throw new ApiException("not_found", "the resource you are trying to retrieve does not exist");
                }
                $response->setContent(json_encode($data));
            });

            $request->matchRest("POST", "/applications/", function() use ($request, $response, $storage, $rs) {
                $rs->requireScope("applications");
                $rs->requireEntitlement("applications");
                try { 
                    $client = ClientRegistration::fromArray(json_decode($request->getContent(), TRUE));
                    $data = $client->getClientAsArray();
                    // check to see if an application with this id already exists
                    if(FALSE === $storage->getClient($data['id'])) {
                        if(FALSE === $storage->addClient($data)) {
                            throw new ApiException("invalid_request", "unable to add application");
                        }
                    } else {
                        throw new ApiException("invalid_request", "application already exists");
                    }
                    $response->setStatusCode(201);
                } catch(ClientRegistrationException $e) {
                    throw new ApiException("invalid_request", $e->getMessage());
                }
            });

            $request->matchRest("PUT", "/applications/:id", function($id) use ($request, $response, $storage, $rs) {
                $rs->requireScope("applications");
                $rs->requireEntitlement("applications");
                try {
                    $client = ClientRegistration::fromArray(json_decode($request->getContent(), TRUE));
                    $data = $client->getClientAsArray();
                    if($data['id'] !== $id) {
                        throw new ApiException("invalid_request", "resource does not match client id value");
                    }
                    if(FALSE === $storage->updateClient($id, $data)) {
                        throw new ApiException("invalid_request", "unable to update application");
                    }
                } catch(ClientRegistrationException $e) {
                    throw new ApiException("invalid_request", $e->getMessage());
                }        
            });

            $request->matchRestDefault(function($methodMatch, $patternMatch) use ($request, $response) {
                if(in_array($request->getRequestMethod(), $methodMatch)) {
                    if(!$patternMatch) {
                        throw new ApiException("not_found", "resource not found");
                    }
                } else {
                    $response->setStatusCode(405);
                    $response->setHeader("Allow", implode(",", $methodMatch));
                }
            });
        } catch (ResourceServerException $e) {
            $response->setStatusCode($e->getResponseCode());
            $response->setHeader("WWW-Authenticate", sprintf('Bearer realm="Resource Server",error="%s",error_description="%s"', $e->getMessage(), $e->getDescription()));
            $response->setContent(json_encode(array("error" => $e->getMessage(), "error_description" => $e->getDescription())));
            if(NULL !== $this->_logger) {
                $this->_logger->logFatal($e->getLogMessage(TRUE) . PHP_EOL . $request . PHP_EOL . $response);
            }
        } catch (ApiException $e) {
            $response->setStatusCode($e->getResponseCode());
            $response->setContent(json_encode(array("error" => $e->getMessage(), "error_description" => $e->getDescription())));
            if(NULL !== $this->_logger) {
                $this->_logger->logFatal($e->getLogMessage(TRUE) . PHP_EOL . $request . PHP_EOL . $response);
            }
        }
        return $response;
    }
}
