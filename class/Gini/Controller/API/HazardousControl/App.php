<?php

namespace Gini\Controller\API\HazardousControl;

class APP extends \Gini\Controller\API\HazardousControl\Base
{
    /**
        * @brief 重写构造函数，避免authorize断言判断
        *
        * @return
     */
    public function __construct()
    {
    }

    public function actionAuthorize($clientID, $clientSecret)
    {
        $confs = \Gini\Config::get('mall.rpc');
        $conf = $confs['node'];
        $url = $conf['url'];
        try {
            $cacheKey = "app#{$url}#{$clientID}#token#{$clientSecret}";
            $token = \Gini\Module\AppBase::cacheData($cacheKey);
            if (!$token) {
                $rpc = \Gini\IoC::construct('\Gini\RPC', $url);
                $token = $rpc->mall->authorize($clientID, $clientSecret);
                \Gini\Module\AppBase::cacheData($cacheKey, $token);
            }
        }
        catch (\Exception $e) {
            throw new \Gini\API\Exception('网络故障', 503);
        }
        if ($token) {
            $this->setCurrentApp($clientID);
            return session_id();
        }
        throw new \Gini\API\Exception('非法的APP', 404);
    }

}
