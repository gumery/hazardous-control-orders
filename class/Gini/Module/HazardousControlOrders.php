<?php

namespace Gini\Module;

class HazardousControlOrders
{
    public static function setup()
    {
    }

    public static function diagnose()
    {
        $errors = [];
        $errors[] = "请确保cron.yml配置了定时刷新的命令\n\t\thazardous-control-orders:\n\t\t\tinterval: '*/5 * * * *'\n\t\t\tcommand: hazardouscontrol relationshipop build\n\t\t\tcomment: 定时将订单的信息刷新到表里，为管理方查数据提供支持";
        return $errors;
    }

    /**
     * [[100, mg], [50, kg], [10, g]]
     */
    private static function _mixData($mix)
    {
        $con1 = ['μl'=>0.001,'ul'=>0.001,'ml'=>1,'cl'=>10,'dl'=>100,'l'=>1000];
        $con2 = ['ug'=>0.000001,'μg'=>0.000001,'mg'=>0.001,'g'=>1,'kg'=>1000];
        $sum = 0;
        foreach ($mix as $vs) {
            $num  = $vs[0];
            $unit = $vs[1];
            if ($mult = $con1[$unit]) {
                $volume = true;
                $u = 'ml';
                $sum +=  $mult * $num;
            }
            elseif ($mult = $con2[$unit]) {
                $weight = true;
                $u = 'g';
                $sum += $mult * $num;
            }
        }
        if (($volume || $weight) && ($volume != $weight)) {
            return [
                'sum' => $sum,
                'unit' => $u
            ];
        }
        return false;
    }

    public static function checkLimit($id = 0, $quantity = 0)
    {
    	$product = a('product', $id);
        $cas_no = $product->cas_no;
        $ulimit = a('hazardous/ulimit', ['cas_no'=>$cas_no]);
        $volume = $ulimit->volume;
        if ($product->id  && $product->type == 'chem_reagent' && $volume) {
            $package = $product->package;
            $pattern = '/^(\d+(?:\.\d+)?)([^0-9]+)$/i';
            if (!preg_match($pattern, $package, $matches)) {
                return false;
            }
            $confs    = \Gini\Config::get('mall.rpc');
            $haz_conf = $confs['haz-monitor'];
            $haz_rpc = \Gini\IoC::construct('\Gini\RPC', $haz_conf['url']);
            $criteria = [
                'cas_no' => $cas_no,
                'node' => \Gini\Config::get('mall.node_id')
            ];
            $order_data = $haz_rpc->stat->hazardous->getDosage($criteria);

            $rpc = \Gini\Module\LabOrders::getAssociatedRPC('lab-inventory');
            $inv_data = $rpc->mall->inventory->getHazardousTotal($criteria);

            $mix = [];
            if (!preg_match($pattern, $volume, $mats)) {
                return false;
            }

            $mix[] = [
                $matches[1]*$quantity,
                $matches[2]
            ];
            if ($order_data) {
                $mix[] = $order_data;
            }
            if ($inv_data) {
                $mix[] = $inv_data;
            }

            $expect = self::_mixData($mix);
            $limit = self::_mixData([[$mats[1], $mats[2]]]);
            if ($expect['unit'] !== $limit['unit']) return false;
            if ($expect['sum'] > $limit['sum']) {
                $overflow = true;
            }
            else {
                $overflow = false;
            }
            return [
                'overflow' => $overflow,
                'name' => $product->name
            ];
        }
    }
}


