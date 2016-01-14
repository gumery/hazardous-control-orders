<?php

namespace Gini\Controller\CLI\HazardousControl;

class RelationshipOP extends \Gini\Controller\CLI
{
    public function __index($args)
    {
        echo "Available commands:\n";
        echo "\tgini hazardouscontrol relationshipop prepare-table\n";
        echo "\tgini hazardouscontrol relationshipop build\n";
    }

    private static function _getTableName()
    {
        return \Gini\Config::get('hazardous-control-orders.table') ?: '_hazardous_control_order_product';
    }

    private static $schema = [
        'fields' => [
            'id' => [
                'type' => 'bigint',
                'serial' => 1,
            ],
            'order_id' => [
                'type' => 'bigint',
            ],
            'order_repeat_id' => [
                'type' => 'bigint',
            ],
            'order_mtime'=> [
                'type'=> 'datetime',
            ],
            'order_md5'=> [
                'type'=> 'varchar(32)',
            ],
            'product_id' => [
                'type' => 'bigint',
            ],
            'product_type' => [
                'type'=> 'varchar(50)'
            ],
            'cas_no'=> [
                'type'=> 'varchar(15)',
                'null'=> true
            ],
            'vendor_id' => [
                'type' => 'bigint',
            ],
            'vendor_name'=> [
                'type' => 'varchar(120)',
            ],
            'group_id' => [
                'type' => 'bigint',
            ],
            'group_name'=> [
                'type' => 'varchar(120)',
            ],
            'order_price'=> [
                'type'=> 'double'
            ],
            'product_package'=> [
                'type' => 'varchar(50)',
            ],
            'product_quantity'=> [
                'type' => 'double',
            ],
            'product_unit_price'=> [
                'type' => 'double',
            ],
            'product_total_price'=> [
                'type' => 'double',
            ],
            'order_status'=> [
                'type'=> 'int',
            ],
            'product_name'=> [
                'type'=> 'varchar(255)',
            ]
        ],
        'indexes' => [
            'PRIMARY' => [
                'type' => 'primary',
                'fields' => ['id'],
            ],
            '_MIDX_PRODUCT_TYPE' => [
                'fields' => ['product_type'],
            ],
            '_MIDX_VENDOR_NAME' => [
                'fields' => ['vendor_name'],
            ],
            '_MIDX_VENDOR_ID' => [
                'fields' => ['vendor_id'],
            ],
            '_MIDX_GROUP_ID' => [
                'fields' => ['group_id'],
            ],
            '_MIDX_GROUP_NAME' => [
                'fields' => ['group_name'],
            ],
            '_MIDX_ORDER_ID' => [
                'fields' => ['order_id'],
            ],
            '_MIDX_ORDER_MD5' => [
                'fields' => ['order_md5'],
            ],
            '_MIDX_ORDER_MTIME' => [
                'fields' => ['order_mtime'],
            ],
        ],
    ];

    public function actionPrepareTable()
    {
        $tableName = self::_getTableName();
        $schema = self::$schema;
        $db = \Gini\Database::db();
        $db->adjustTable($tableName, $schema);
        if ($db->query("DESC {$tableName}")) {
            echo "Prepare Table {$tableName}: Done\n";
        }
        else {
            echo "Prepare Table {$tableName}: Failed\n";
        }
    }

    private function getMd5($row)
    {
        return md5(J($row->items).$row->status);
    }

    public function actionBuild()
    {
        $pidFile = APP_PATH.'/'.DATA_DIR.'/hazardous-control-orders-build.pid';
        if (file_exists($pidFile)) {
            $rawPID = (int) file_get_contents($pidFile);
            // 如果进程已经在执行 直接退出
            if ($rawPID && $this->filterWorkers($rawPID)) {
                return;
            }
        }
        $pid = getmypid();
        file_put_contents($pidFile, $pid);

        $db = \Gini\Database::db();
        $tableName = self::_getTableName();
        $max = $db->query("SELECT max(order_mtime) FROM {$tableName}")->value() ?: 0;
        $start = 0;
        $perpage = 100;
        $schema = self::$schema;
        $keys = array_keys($schema['fields']);
        array_shift($keys);
        $keys = implode(',', $keys);
        while (true) {
            $rows = those('order')->whose('mtime')->isGreaterThan($max)->orderBy('mtime', 'asc')->limit($start, $perpage);
            if (!count($rows)) break;
            $start += $perpage;

            $values = [];
            foreach ($rows as $row) {
                $items = (array) $row->items;

                $qRowID = $db->quote($row->id);
                $qRowMd5 = $db->quote($this->getMd5($row));
                // 检测有没有历史数据
                $hisCount = $db->query("SELECT COUNT(*) FROM {$tableName} WHERE order_id={$qRowID}")->value() ?: 0;
                if ($hisCount) {
                    // 检测历史数据是不是已经跟最新的数据不一致了
                    // 如果不一致，需要删除重建
                    // 如果一致，就不需要在重复添加了
                    $query = $db->query("DELETE FROM {$tableName} WHERE order_id={$qRowID} AND order_md5!={$qRowMd5}");
                    if (!$query || !$query->count()) {
                        echo "\norder#{$row->id} 更新数据失败\n";
                        continue;
                    }
                }

                foreach ($items as $i=>$item) {
                    $product = isset($item['version']) ? a('product', [
                        'id'=> $item['id'],
                        'version'=> $item['version']
                    ]) : a('product', $item['id']);
                    // 商品会不会不存在？
                    if (!$product->id) continue;
                    $myType = $this->_getProductType($product->type, $product->rgt_type, $product->cas_no);
                    if (!$myType) continue;
                    $values[] = '(' . implode(',',[
                        // orderid
                        $db->quote($row->id),
                        // order repeat id
                        $db->quote($i==0 ? $row->id:0),
                        // orderctime
                        $db->quote($row->mtime),
                        // ordermd5
                        $db->quote($this->getMd5($row)),
                        // productid
                        $db->quote($product->id),
                        // producttype
                        $db->quote($myType),
                        // casno
                        $db->quote($product->cas_no),
                        // vendorid
                        $db->quote($row->vendor->id),
                        // vendorname
                        $db->quote($row->vendor->name),
                        // groupid
                        $db->quote($row->group->id),
                        // groupname
                        $db->quote($row->group->title),
                        // orderprice
                        $db->quote(round($row->price)),
                        // product package
                        $db->quote($product->package),
                        // product quantity
                        $db->quote($item['quantity']),
                        // product unit price
                        $db->quote($item['unit_price']),
                        // product total price
                        $db->quote($item['price']),
                        // order status
                        $db->quote($row->status),
                        // product name
                        $db->quote($this->_getProductName($item['name'], $product->cas_no, $myType)),
                    ]) . ')';
                }
            }
            if (empty($values)) continue;
            $values = implode(',', $values);
            $db = \Gini\Database::db();
            $sql = "INSERT INTO {$tableName}({$keys}) VALUES {$values};";
            if ($db->query($sql)) {
                echo '.';
            }
            else {
                echo 'x';
            }
        }
    }

    private static $rpc;
    private static function _getRPC()
    {
        if (self::$rpc) return self::$rpc;
        $config = \Gini\Config::get('hazardous-control-orders.rpc');
        $url = $config['url'];
        $rpc = \Gini\IoC::construct('\Gini\RPC', $url);
        self::$rpc = $rpc;
        return $rpc;
    }

    private static $data = [];
    private function _getProductName($name, $cas=null, $type=null)
    {
        if (!$cas) return $name;
        if (isset(self::$data[$cas])) {
            $data = self::$data[$cas];
        }
        else {
            $rpc = self::_getRPC();
            self::$data[$cas] = $data = (array)$rpc->product->chem->getProduct($cas);
        }
        if (empty($data)) {
            return $name;
        }
        if ($type) {
            foreach ($data as $d) {
                if ($d['type']==$type) {
                    return $d['name'];
                }
            }
        }
        return $name;
    }

    private function _getProductType($type, $subType=null, $cas=null)
    {
        if ($cas) {
            if (isset(self::$data[$cas])) {
                $data = self::$data[$cas];
            }
            else {
                $rpc = self::_getRPC();
                self::$data[$cas] = $data = (array)$rpc->product->chem->getProduct($cas);
            }
            foreach ($data as $d) {
                return $d['type'];
            }
        }
        $types = [
            \Gini\ORM\Product::RGT_TYPE_HAZARDOUS=> 'hazardous',
            \Gini\ORM\Product::RGT_TYPE_DRUG_PRECURSOR=> 'drug_precursor',
            \Gini\ORM\Product::RGT_TYPE_HIGHLY_TOXIC=> 'highly_toxic'
        ];
        if ($subType && isset($types[$subType])) {
            return $types[$subType];
        }
        return $type;
    }

    private function filterWorkers($pid)
    {
        $ps = shell_exec("ps -p {$pid}");

        return count(explode("\n", $ps)) > 2;
    }
}
