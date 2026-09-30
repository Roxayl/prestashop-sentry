<?php

class Hook extends HookCore
{
    public static function coreCallHook($module, $method, $params)
    {
        if (\class_exists('Extalion\Sentry\Tracing\Tracer', false) && \Extalion\Sentry\Tracing\Tracer::isActive()) {
            return \Extalion\Sentry\Tracing\Tracer::traceHook($module->name . '::' . $method, static function () use ($module, $method, $params) {
                return parent::coreCallHook($module, $method, $params);
            });
        }

        return parent::coreCallHook($module, $method, $params);
    }
}
