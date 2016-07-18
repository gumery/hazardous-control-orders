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
        $errors[] = "请确保cron.yml配置了定时刷新的命令\n\t\thazardous-control-orders:\n\t\t\tinterval: '30 3 * * *'\n\t\t\tcommand: hazardouscontrol relationshipop build\n\t\t\tcomment: 定时将订单的信息刷新到表里，为管理方查数据提供支持";

        $db = \Gini\Database::db();
        $tableName = \Gini\Config::get('hazardous-control-orders.table') ?: '_hazardous_control_order_product';
        if (!$db->query("DESC {$tableName}")) {
            $errors[] = "请确保 {$tableName} 表已经创建: gini hazardouscontrol relationshipop prepare-table";
            $errors[] = "如果是初次部署建议初始化数据: gini hazardouscontrol relationshipop build";
        }

        return $errors;
    }

    public static function getUnableProducts($e, $products, $group, $user)
    {
        if (!count($products)) {
            $e->pass();
            return;
        }
        $conf    = \Gini\Config::get('mall.rpc');
        $client  = \Gini\Config::get('mall.client');
        $url     = $conf['lab-inventory']['url'];
        $rpc     = \Gini\IoC::construct('\Gini\RPC', $url);
        $group_id = $group->id;
        $token   = $rpc->mall->authorize($client['id'], $client['secret']);
        if (!$token) {
            $e->abort();
            return ['error' => H(T('存货管理连接中断'))];
        }
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
            $lNum = $i->from($volume)->to('g');
            if ((string)$volume === '') continue;
            // 如果设置为0不允许购买
            if ((string)$lNum === '0') {
                $data[] = [
                    'reason' => H(T('该商品不允许购买')),
                    'id' => $info['id'],
                    'name' => $product->name
                ];
            }
            elseif ($lNum) {
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
                $conf = self::_getHazConf($cas_no, $group_id);
                $count_cart = $conf['count_cart'];
                $iNum = $i->from($idata['volume'])->to('g');
                if ($count_cart) {
                    $pNum = $i->from($pdata['volume'])->to('g');
                    $sum = $iNum +$pNum;
                }
                else {
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

        if (!empty($data)) {
            $e->abort();
            return $data;
        }
        $e->pass();
    }

    public static function getChemicalTypes() {

        $types = [
            'all' => '全部化学品',
            'hazardous' => '危化品',
            'drug_precursor' => '易制毒',
            'explosive' => '易制爆',
            'highly_toxic' => '剧毒品'
        ];

        return $types;
    }

    protected static $_RPCs = [];
    public static function getRPC($type = 'lab-inventory')
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

    private static function _getHazConf($cas_no, $group_id) {
        $conf = [];
        $cache = \Gini\Cache::of('cas-conf');
        $key = "haz-conf[$cas_no][$group_id]";
        $conf = $cache->get($key);
        if (false === $conf) {
            $rpc = self::getRPC('hazardous-control');
            $criteria = ['cas_no'=>$cas_no, 'group_id'=>$group_id];
            $conf = $rpc->inventory->getHazConf($criteria);
            $cache->set($key, $conf, 15);
        }
        return $conf;
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

    public static function headerSettingsMenu($e, $menu) {
        $me = _G('ME');
        if ($me->id && $me->isAllowedTo('管理订单')) {
            $menu['general']['chemical-limits'] = [
                'url' => 'settings/chemical-limits',
                'title' => T('化学品库存上限'),
            ];
        }
    }
}


