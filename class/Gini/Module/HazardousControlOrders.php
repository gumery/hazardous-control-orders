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

        $mode = \Gini\Config::get('hazardous.mode');
        if (!$mode) {
            $errors[] = '您确定不需要设置hazardous.mode 【inv-limit | inv-exists】? 这个值将影响存量上线的逻辑';
        }

        return $errors;
    }

    public static function getUnableProducts($e, $products, $group, $user)
    {
        if (!count($products)) return [];
        $mode    = \Gini\Config::get('hazardous.mode');
        $conf    = \Gini\Config::get('mall.rpc');
        $client  = \Gini\Config::get('mall.client');
        $url     = $conf['lab-inventory']['url'];
        $rpc     = \Gini\IoC::construct('\Gini\RPC', $url);
        $group_id = $group->id;
        $token   = $rpc->mall->authorize($client['id'], $client['secret']);
        if (!$token) return ['error' => H(T('存货管理连接中断'))];
        foreach ($products as $info) {
            if ($info['customized']) continue;
            $product = a('product', $info['id']);
            if (!$product->cas_no) continue;
            $cas_no  = $product->cas_no;
            $package = $product->package;
            $i       = \Gini\Unit\Conversion::of($product);
            $ldata   = self::_getCasVolume($i, $cas_no, $group_id);
            if ($ldata['error']) {
                    $data[] = [
                        'reason' => $ldata['error'],
                        'id' => $info['id'],
                        'name' => $product->name
                    ];
            }
            $volume = $ldata['volume'];

            if ((string)$volume === '') continue;
            // 如果设置为0不允许购买
            if ((string)$volume === '0') {
                $data[] = [
                    'reason' => H(T('该商品不允许购买')),
                    'id' => $info['id'],
                    'name' => $product->name
                ];
            }
            elseif ($volume) {
                $pdata = self::_getProductVolume($i, $package, $info['quantity']);
                if ($pdata['error']) {
                    $data[] = [
                        'reason' => $pdata['error'],
                        'id' => $info['id'],
                        'name' => $product->name
                    ];
                    continue;
                }
                $idata = self::_getInventoryVolume($cas_no, $group_id);
                if ($idata['error']) {
                    $data[] = [
                        'reason' => $idata['error'],
                        'id' => $info['id'],
                        'name' => $product->name
                    ];
                    continue;
                }

                $iNum = $i->from($idata['volume'])->to('g');
                $lNum = $i->from($volume)->to('g');
                if ($mode == 'inv-limit') {
                    $pNum = $i->from($pdata['volume'])->to('g');
                    $sum = $iNum +$pNum;
                }
                elseif ($mode == 'inv-exists') {
                    $sum = $iNum;
                }

                if ($sum > $lNum) {
                    $data[] = [
                        'reason' => H(T('超出该商品的管控上限')),
                        'id' => $info['id'],
                        'name' => $product->name
                    ];
                }
            }
        }
        return $data?:[];
    }



    protected static $_RPCs = [];
    protected static function getRPC($type = 'lab-inventory')
    {
        $confs = \Gini\Config::get('mall.rpc');
        $conf = $confs[$type] ?: [];
        if (!self::$_RPCs[$type]) {
            $rpc = \Gini\IoC::construct('\Gini\RPC', $conf['url']);
            self::$_RPCs[$type] = $rpc;
            $client = \Gini\Config::get('mall.client');
            if ($type == 'lab-inventory') {
                $token = $rpc->mall->authorize($client['id'], $client['secret']);
                if (!$token) {
                    \Gini\Logger::of('lab-orders')
                        ->error('Mall\\RObject getRPC: authorization failed with {client_id}/{client_secret} !',
                            [ 'client_id' => $client['id'], 'client_secret' => $client['secret']]);
                }
            }
        }

        return self::$_RPCs[$type];
    }

    private static function _getCasVolume($i, $cas_no, $group_id) {
        if (!$group_id){
            return ['error'=>'组信息丢失'];
        }
        $cache = \Gini\Cache::of('cas-volume');
        $key = "cas-volume-limit[$cas_no][$group_id]";
        $volume = $cache->get($key);
        if (false === $volume) {
            $rpc = self::getRPC('hazardous-control');
            $criteria = ['cas_no'=>$cas_no, 'group_id'=>$group_id];
            $volume = $rpc->inventory->getLimitVolume($criteria);
            $cache->set($key, $volume, 5);
        }
        if ($volume && !$i->validate($volume)) {
            return ['error' => H(T('存量上限单位异常'))];
        }
        return ['volume'=>$volume];
    }

    private static function _getInventoryVolume($cas_no, $group_id) {
        if (!$group_id){
            return ['error'=>'组信息丢失'];
        }
        $cache = \Gini\Cache::of('cas-volume');
        $key = "cas-volume-inv[$cas_no][$group_id]";
        $volume = $cache->get($key);
        if (false === $volume) {
            $rpc = self::getRPC('lab-inventory');
            $volume = $rpc->mall->inventory->getHazardousTotal($cas_no, $group_id);
            $cache->set($key, $volume, 15);
        }
        return ['volume' => $volume];
    }

    private static function _getProductVolume($i, $package, $quantity) {
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

        return [
            'volume' => ($p_num * (int)$quantity).$p_unit,
        ];
    }
}


