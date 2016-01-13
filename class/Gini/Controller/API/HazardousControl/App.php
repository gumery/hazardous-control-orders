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
        $conf = \Gini\Config::get('gapper.rpc');
        try {
            $rpc = \Gini\IoC::construct('\Gini\RPC', $conf['url']);
            $bool = $rpc->gapper->app->authorize($clientID, $clientSecret);
        }
        catch (\Exception $e) {
            throw new \Gini\API\Exception('网络故障', 503);
        }
        if ($bool) {
            $this->setCurrentApp($clientID);
            return session_id();
        }
        throw new \Gini\API\Exception('非法的APP', 404);
    }

}
