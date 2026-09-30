<?php

declare(strict_types=1);

// Stands in for the PrestaShop core class that the DbPDO override extends: query() runs _query().
class DbPDOCore
{
    /** @var mixed */
    public $result = [];

    public ?\Throwable $failure = null;

    /** @var string[] */
    public array $executed = [];

    /**
     * @return mixed
     */
    public function query(string $sql)
    {
        return $this->_query($sql);
    }

    /**
     * @return mixed
     */
    protected function _query($sql)
    {
        $this->executed[] = $sql;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }
}
