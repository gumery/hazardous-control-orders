<?php
/**
* @file Ulimit.php
* @brief 该接口被hazardouscontrol app调用，用于设置ulimit
* @author PiHiZi <pihizi@msn.com>
* @version 0.1.0
* @date 2016-04-09
 */

namespace Gini\Controller\API\HazardousControl;

class Ulimit extends \Gini\Controller\API\HazardousControl\Base
{
    /**
        * @brief 设置危化品的可购买上限, 允许为空，为零
        *
        * @param $params
        *       cas_no: 
        *       volume: 
        *
        * @return 
     */
    public function actionSet(array $params)
    {
        $casNO = $params['cas_no'];
        if (!$casNO) return false;
        $volume = $params['volume'];
        $ulimit = a('hazardous/ulimit', ['cas_no'=> $casNO]);
        if (!$ulimit->id) {
            $ulimit->cas_no = $casNO;
        }
        $ulimit->volume = $volume;
        $bool = $ulimit->save();
        return $bool;
    }
}
