<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Mysql\Adapter;

use Jmonitor\Utils\DatabaseAdapter\PdoAdapter as BasePdoAdapter;

/**
 * @deprecated Moved into \Jmonitor\Utils\DatabaseAdapter
 */
class PdoAdapter extends BasePdoAdapter implements MysqlAdapterInterface {}
