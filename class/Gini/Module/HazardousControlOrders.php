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

        $rpc = \Gini\Module\AppBase::getAppRPC('lab-inventory');

        $newTNS = [];
        $errorCas = [];
        foreach ($products as $info) {
            if ($info['customized']) continue;
            $product = a('product', $info['id']);
            if (!$product->cas_no) continue;

            if (in_array($product->cas_no, $errorCas)) {
                continue;
            }

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

            $lNum = $i->from($volume)->to('g');
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

                $sum = $iNum;
                $newTNS[$cas_no] = $newTNS[$cas_no] ?: $sum;
                if ($count_cart) {
                    $pNum = $i->from($pdata['volume'])->to('g');
                    $newTNS[$cas_no] += $pNum;
                }

                if ($newTNS[$cas_no] > $lNum) {
                    $errorCas[] = $cas_no;
                    $data[] = [
                        'reason' => H(T('当前存量为:sumg ，管制上限为:lNumg，新购买的商品量将导致存量超过该商品的管制上限，请减少存量后再进行购买', [':sum' => $sum, ':lNum' => $lNum])),
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
            'highly_toxic' => '剧毒品',
            'psychotropic'=> '精神药品',
            'narcotic'=> '麻醉药品',
        ];

        return $types;
    }

    private static function _getHazConf($cas_no, $group_id) {
        $conf = [];
        $cache = \Gini\Cache::of('cas-conf');
        $key = "haz-conf[$cas_no][$group_id]";
        $conf = $cache->get($key);
        if (false === $conf) {
            $rpc = \Gini\Module\AppBase::getAppRPC('chemical-limits');
            $criteria = ['cas_no'=>$cas_no, 'group_id'=>$group_id];
            $conf = $rpc->admin->inventory->getHazConf($criteria);
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
            $rpc = \Gini\Module\AppBase::getAppRPC('chemical-limits');
            $criteria = ['cas_no'=>$cas_no, 'group_id'=>$group_id];
            $volume = $rpc->admin->inventory->getLimit($criteria);
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
            $rpc = \Gini\Module\AppBase::getAppRPC('lab-inventory');
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

    public static function headerTopMenu($e, $menu) {
        $me = _G('ME');
        if ($me->id && $me->isAllowedTo('管理订单')) {
            $menu['settings']['general']['chemical-limits'] = [
                '@url' => 'settings/chemical-limits',
                '@title' => T('化学品库存上限'),
            ];
        }
    }
}


