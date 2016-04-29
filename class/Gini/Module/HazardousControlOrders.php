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

    public static function getUnableProducts($e, $products, $group, $user)
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
            if (!$ulimit->id || ((string)$ulimit->volume === '')) continue;
            // 如果设置为0不允许购买
            if ((string)$ulimit->volume === '0') {
                $data[] = [
                    'reason' => H(T('该商品不允许购买')),
                    'id' => $info['id'],
                    'name' => $product->name
                ];
            }
            elseif ($volume = $ulimit->volume) {
                if (!\Gini\Unit\Conversion::of($product)->validate($ulimit->volume)) {
                    return ['error' => H(T('存量上限单位异常'))];
                }

                $pattern = '/^(\d+(?:\.\d+)?)([A-Za-zμ3]+)(\s?\\*\s?\d+)?$/i';
                if (!preg_match($pattern, $package, $matches)) {
                    return ['error' => H(T('商品包装异常'))];
                }

                // 商品包装 商品单位
                $p_num = $matches[1];
                $p_unit = $matches[2];
                $pattern = '/^(\s?\\*\s?)(\d+)$/i';
                if ( ($addition = $matches[3]) &&  preg_match($pattern, $addition, $mat)) {
                    $mult = $mat[2];
                    $p_num = $p_num * (int)$mult;
                }

                $pTotal = ($p_num * (int)$info['quantity']).$p_unit;

                $total = $rpc->mall->inventory->getHazardousTotal($cas_no, $group->id);
                // 存量超出上限
                if ($mode == 'inv-limit') {
                    $i = \Gini\Unit\Conversion::of($product);
                    $iNum = $i->from($total)->to('g');
                    $pNum = $i->from($pTotal)->to('g');
                    $lNum = $i->from($volume)->to('g');
                    $sum = $iNum +$pNum;
                    if ($sum > $lNum) {
                        $data[] = [
                            'reason' => H(T('超出危化品管控上限')),
                            'id' => $info['id'],
                            'name' => $product->name
                        ];
                    }
                }
                // 存量有无限制
                elseif ($mode == 'inv-exists') {
                if (!$total) continue;
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
}


