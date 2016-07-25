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
        try {
            $rpc = \Gini\IoC::construct('\Gini\RPC', $conf['url']);
            $bool = $rpc->mall->authorize($clientID, $clientSecret);
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
