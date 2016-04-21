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

    public static function getUnableProducts($e, $products)
    {
        if (!count($products)) return [];
        $mode = \Gini\Config::get('hazardous.mode');
        $data = [];
        $conf = \Gini\Config::get('mall.rpc');
        $client = \Gini\Config::get('mall.client');
        $url  = $conf['lab-inventory']['url'];
        $rpc  = \Gini\IoC::construct('\Gini\RPC', $url);
        $token = $rpc->mall->authorize($client['id'], $client['secret']);
        if (!$token) return ['error' => H(T('存货管理连接中断'))];
        foreach ($products as $info) {
            if ($info['customized']) continue;
            $product = a('product', $info['id']);
            if (!$product->cas_no) continue;
            $cas_no = $product->cas_no;
            $package = $product->package;
            $ulimit = a('hazardous/ulimit', ['cas_no'=>$cas_no]);
            if (!$ulimit->id || ($ulimit->volume == NULL)) continue;
            // 如果设置为0不允许购买
            if ($ulimit->volume == 0) {
                $data[] = [
                    'reason' => H(T('该商品不允许购买')),
                    'id' => $info['id'],
                    'name' => $product->name
                ];
            }
            elseif ($ulimit->volume) {
                $pattern = '/^(\d+(?:\.\d+)?)([^0-9]+)$/i';
                if (!preg_match($pattern, $ulimit->volume, $matches)) {
                    return ['error' => H(T('存量上限单位异常'))];
                }
                $pattern2 = '/^(\d+(?:\.\d+)?)([A-Za-zμ]+)(\s?\\*\s?\d+)?$/i';
                if (!preg_match($pattern2, $package, $matches2)) {
                    return ['error' => H(T('商品包装异常'))];
                }
                else {
                    $p_num = $matches2[1] * (int)$info['quantity'];
                    $p_unit = $matches2[2];
                    $pattern3 = '/^(\s?\\*\s?)(\d+)$/i';
                    if ( ($addition = $matches2[3]) &&  preg_match($pattern3, $addition, $matches3)) {
                        $mult = $matches3[2];
                        $p_num = $p_num * (int)$mult;
                    }
                }
                $ret = $rpc->mall->inventory->getHazardousTotal($cas_no);
                $num = $ret['dosage'];
                $unit = $ret['unit'];
                if ($num == 0) continue;
                // 存量超出上线
                if ($mode == 'inv-limit') {
                    $inv_data   = ['num'=>$num, 'unit'=>$unit];
                    $p_data = ['num'=>$p_num, 'unit'=>$p_unit];
                    $limit_data = ['num'=>$matches[1], 'unit'=>$matches[2]];
                    $pre   = self::_mixData([$inv_data, $p_data]);
                    $limit = self::_mixData([$limit_data]);
                    if (!$pre || !$limit || ($pre['unit'] !== $limit['unit'])) {
                        return ['error' => H(T('危化品无法进行统计'))];
                    }
                    if ($pre['sum'] > $limit['sum']) {
                        $data[] = [
                            'reason' => H(T('超出危化品管控上线')),
                            'id' => $info['id'],
                            'name' => $product->name
                        ];
                    }
                }
                // 存量有无限制
                elseif ($mode == 'inv-exists') {
                    // 如果有存货就不可以购买
                    $data[] = [
                        'reason' => H(T('该商品有存货, 不允许购买')),
                        'id' => $info['id'],
                        'name' => $product->name
                    ];
                }
            }
        }
        return $data;
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
            $num  = $vs['num'];
            $unit = $vs['unit'];
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

}


