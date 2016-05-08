<?php

/**
* @file Ulimit.php
* @brief 危化品购买上限, 为订单购买提供的扩展功能
* @author PiHiZi <pihizi@msn.com>
* @version 0.1.0
* @date 2016-04-09
 */
namespace Gini\ORM\Hazardous;

class Ulimit extends \Gini\ORM\Object
{
    public $cas_no = 'string:120';
    public $volume = 'string:120';

    protected static $db_index = [
        'unique:cas_no',
    ];

    const CAS_DEFAULT_ALL   = 'all';
    const CAS_DEFAULT_HAZ   = 'hazardous';
    const CAS_DEFAULT_DRUG  = 'drug_precursor';
    const CAS_DEFAULT_TOXIC = 'highly_toxic';
    const CAS_DEFAULT_EXP   = 'explosive';

    static $default_cas_nos = [
		self::CAS_DEFAULT_ALL   => '全部',
		self::CAS_DEFAULT_HAZ   => '危化品',
		self::CAS_DEFAULT_DRUG  => '易制毒',
		self::CAS_DEFAULT_TOXIC => '剧毒品',
		self::CAS_DEFAULT_EXP   => '易制爆',
    ];

    protected static $TIMEOUT = 86400;

    private $_RPC;
    public static function getRPC()
    {
        if (!$_RPC) {
            $conf  = \Gini\Config::get('chem.rpc');
            $_RPC = new \Gini\RPC($conf['url'] ?: strval($conf));
        }

        return $_RPC;
    }

    public function save()
    {
        return parent::save();
    }

    public static function getVolume($i, $cas)
    {
    	$ulimit = a('hazardous/ulimit', ['cas_no'=>$cas]);
    	if ($ulimit->id && $ulimit->volume !== '') {
    		return $ulimit->volume;
    	}
    	else {
    		$cache = \Gini\Cache::of('cas-info');
    		$key = "cas-key[$cas]";
    		$infos = $cache->get($key);
    		if (false === $infos) {
    			$rpc = self::getRPC();
    			$infos = $rpc->product->chem->getProduct($cas);
                $cache->set($key, $infos, static::$TIMEOUT);
    		}
    		if (!$infos) return NULL;
            $types = array_keys($infos);
            $types[] = 'all';
            $ulimits = Those('hazardous/ulimit')->Whose('cas_no')->isIn($types);
            $n = 0;
            $min_ulimit = a('hazardous/ulimit');
            foreach ($ulimits as $ulimit) {
                $volume = $ulimit->volume;
                if ((string)$volume === '0') return $volume;
                if ($volume) {
                    $v = $i->from($volume)->to('g');
                    if (!$v) continue;
                    $n++;
                    if ($n == 1 || ($min_v && $v < $min_v)) {
                        $min_v = $v;
                        $min_ulimit = $ulimit;
                    }
                }
            }
            if ($min_ulimit->id) {
                return $min_ulimit->volume;
            }
            return NULL;
    	}
    }
}
