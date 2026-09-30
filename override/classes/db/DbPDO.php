<?php

class DbPDO extends DbPDOCore
{
    protected function _query($sql)
    {
        if (\class_exists('Extalion\Sentry\Tracing\Tracer', false) && \Extalion\Sentry\Tracing\Tracer::isActive()) {
            return \Extalion\Sentry\Tracing\Tracer::traceQuery((string) $sql, function () use ($sql) {
                return parent::_query($sql);
            });
        }

        return parent::_query($sql);
    }
}
