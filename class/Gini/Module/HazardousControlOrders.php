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
    /*
     * getUnableProduct自购逻辑
     *
     */
    private static function _promptCustomized($info, $product, $cas_no, $group_id)
    {
        // 存量上限、无法购买
        // 获取自购无法购买的类型
        $customizedNotBuy = \Gini\Config::get('order.customized_not_buy_types');
        $customizedNotBuy = ($customizedNotBuy != '${LAB_ORDERS_CUSTOMIZED_NOT_BUY_TYPES}') ? explode(',', $customizedNotBuy) : [];
        $customizedPrompt = \Gini\Config::get('app.customized_chemical_prompt_enable');

        // 无法购买
        if (!empty($customizedNotBuy)) {
            // 根据cas_no获取商品的化学品类型
            $chemicalInfo = \Gini\ChemDB\Client::getTypes($cas_no);
            // TODO 获取rgtType,rgtTitle组合后的数组
            $rgtTypeAndRgtTitle = self::combineRgtTypeAndRgtTitle();
            // 自购配置和自购商品类型求交集
            $tmpType = array_intersect($customizedNotBuy, (array)$chemicalInfo[$cas_no]);
            if (!empty($tmpType)) {
                // 将类型转换为对应的中文
                $tmpType = array_intersect_key($rgtTypeAndRgtTitle, array_flip($tmpType));
                $data[] = [
                    'reason' => H(T('是: :type (自购禁止购买该类型商品)', [':type' => implode(', ', $tmpType)])),
                    'id' => $product->id,
                    'name' => $product->name
                ];
                return $data;
            }
        }

        if ($customizedPrompt === true) {
            return self::_promptCommon($info, $product, $cas_no, $group_id);
        }
    }

    /*
     * getUnableProduct普通商品逻辑
     *
     */
    private static function _promptGeneral($info,$product, $cas_no, $group_id)
    {
        return self::_promptCommon($info, $product, $cas_no, $group_id);
    }

    /*
     * 这个方法一般不需要改动
     *
     */
    private static $newTNS = [];
    private static $errorCAS = [];
    private static function _promptCommon($info, $product, $cas_no, $group_id)
    {
        $errors = [];
        $package = $product->package;
        $i       = \Gini\Unit\Conversion::of($product);
        $ldata   = self::_getCasVolume($i, $cas_no, $group_id);
        if ($ldata['error']) {
            $errors[] = [
                'reason' => $ldata['error'],
                'id' => $info['id'],
                'name' => $product->name
            ];
        }
        $volume = $ldata['volume'];
        if ((string)$volume === '') return $errors;

        $lNum = $i->from($volume)->to('g');
        // 如果设置为0不允许购买
        if ((string)$lNum === '0') {
            $errors[] = [
                'reason' => H(T('该商品不允许购买')),
                'id' => $info['id'],
                'name' => $product->name
            ];
            return $errors;
        }

        if ($lNum) {
            $pdata = self::_getProductVolume($i, $package, $info['quantity']);
            if ($pdata['error']) {
                $errors[] = [
                    'reason' => $pdata['error'],
                    'id' => $info['id'],
                    'name' => $product->name
                ];
                return $errors;
            }

            $idata = self::_getInventoryVolume($cas_no, $group_id);
            if ($idata['error']) {
                $errors[] = [
                    'reason' => $idata['error'],
                    'id' => $info['id'],
                    'name' => $product->name
                ];
                return $errors;
            }

            if (in_array($cas_no, self::$errorCAS)) {
                return $errors;
            }

            $conf = self::_getHazConf($cas_no, $group_id);
            $count_cart = $conf['count_cart'];
            $iNum = $i->from($idata['volume'])->to('g');

            $sum = $iNum;
            self::$newTNS[$cas_no] = self::$newTNS[$cas_no] ?: $sum;
            if ($count_cart) {
                $pNum = $i->from($pdata['volume'])->to('g');
                self::$newTNS[$cas_no] += $pNum;
            }

            if (self::$newTNS[$cas_no] > $lNum) {
                self::$errorCAS[] = $cas_no;
                $errors[] = [
                    'reason' => H(T('当前存量为:sumg ，管制上限为:lNumg，新购买的商品量将导致存量超过该商品的管制上限，请减少存量后再进行购买', [':sum' => $sum, ':lNum' => $lNum])),
                    'id' => $info['id'],
                    'name' => $product->name
                ];
            }
        }
        return $errors;
    }

    public static function getUnableProducts($e, $products, $group, $user)
    {
        if (!count($products)) {
            $e->pass();
            return;
        }

        $group_id = $group->id;
        $data = [];
        foreach ($products as $info) {

            // 自购化学品提示
            if ($info['customized']) {
                $product = a('product/customized', $info['id']);
                $cas_no = $product->cas_no;
                if (!$cas_no) continue;

                $myError = self::_promptCustomized($info, $product, $cas_no, $group_id);
                if (!empty($myError)) {
                    $data = array_merge($data, $myError);
                }
            } else {
                $product = a('product', $info['id']);
                $cas_no = $product->cas_no;
                if (!$cas_no) continue;

                $myError = self::_promptGeneral($info, $product, $cas_no, $group_id);
                if (!empty($myError)) {
                    $data = array_merge($data, $myError);
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

    private static $typeTitle;
    public static function combineRgtTypeAndRgtTitle()
    {
        $rgtTypes = \Gini\ORM\Product::$rgt_types;
        $rgtTitles = \Gini\ORM\Product::$rgt_titles;

        if (!self::$typeTitle) {
            foreach ($rgtTypes as $k => $v) {
                if ($rgtTitles[$k]) {
                    $ret[$v] = $rgtTitles[$k];
                    self::$typeTitle = $ret;
                }
            }
        }

        return (array) self::$typeTitle;
    }
}


