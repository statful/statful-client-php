<?php

/**
 * The UDP Client for Telemetron.
 *
 * @constructor
 */
class TelemetronClient {

    private $host;
    private $port;
    private $prefix;
    private $socket;
    private $sampleRate;
    private $buffer;
    public $enviroment;
    public $platform;
    public $app;
    public $tags = array();
    public $mock = false;
    public $namespace = 'application';

    /**
     * Initialize the Telemetron udp client
     * @param string host statsd host address
     * @port string port statsd host port
     * @prefix string prefix metric prefix
     * @environment string environment tag
     * @platform string platform tag
     * @app string app tag
     * @tags array custom tags. Ex: ['tagName' => 'value']
     **/
    function __construct($host = '127.0.0.1', $port = '2013', $prefix = '', $environment = '', $platform = '', $app = '', $tags = array()) {
        $this->host = $host;
        $this->port = $port;
        $this->prefix = $prefix;
        $this->environment = $environment;
        $this->platform = $platform;
        $this->app = $app;
        $this->tags = $tags;
        $this->buffer = array();

        try {
            $this->socket = fsockopen("udp://$this->host", $this->port, $errno, $errstr);
        }
        catch (Exception $e) {

        }

        if (!$this->socket && !$this->mock) { return; }
    }

    /**
     * Sends a timing metric
     *
     * @param name Name of the counter. Ex: response_time
     * @param value
     * @param tags Tags to associate this value with, for example {from: 'serviceA', to: 'serviceB', method: 'login'}
     * @param namespace Define the metric namespace. Default: application
     * @param agg List of aggregations to be applied by the Telemetron. Ex: ['avg', 'p90', 'min']
     * @param aggFreq Aggregation frequency in seconds. One of: 10, 15, 30, 60 or 300
     */
    public function time($name, $value, $tags = array(), $namespace = 'application', $agg = null, $aggFreq = 10) {
        if(!$agg) $agg = array('avg', 'p90', 'count', 'count_ps');
        $type = array('unit' => 'ms');
        if(!$value || $value < 0) $value = 0;

        $this->put('timer.'.$name, array_merge($type, $tags), $value, $agg, $aggFreq, $this->sampleRate, $namespace);
    }

    /**
     * Increments a counter
     *
     * @param name Name of the counter. Ex: transactions
     * @param value
     * @param tags Tags to associate this value with, for example {type: 'purchase_order'}
     * @param namespace Define the metric namespace. Default: application
     * @param agg List of aggregations to be applied by the Telemetron. Ex: ['avg', 'p90', 'min']
     * @param aggFreq Aggregation frequency in seconds. One of: 10, 15, 30, 60 or 300
     */
    public function inc($name, $value, $tags = array(), $namespace = 'application', $agg = null, $aggFreq = 10) {
        if(!$agg) $agg = array('sum', 'count', 'count_ps');
        if(!$value || $value < 0) $value = 0;

        $this->put('counter.'.$name, $tags, $value, $agg, $aggFreq, $this->sampleRate, $namespace);
    }

    /**
     * Adds a Gauge
     * @param name Name of the Gauge. Ex: current_sessions
     * @param value
     * @param tags Tags to associate this value with, for example {page: 'overview'}
     * @param namespace Define the metric namespace. Default: application
     * @param agg List of aggregations to be applied by the Telemetron. Ex: ['avg', 'p90', 'min']
     * @param aggFreq Aggregation frequency in seconds. One of: 10, 15, 30, 60 or 300
     */
    public function gauge($name, $value, $tags = array(), $namespace = 'application', $agg = array('last'), $aggFreq = 10) {
        if(!$agg) $agg = array('last');
        if(!$value || $value < 0) $value = 0;

        $this->put('counter'.$name, $tags, $value, $agg, $aggFreq, $this->sampleRate, $namespace);
    }

    /**
     * Adds a new metric to the in-memory buffer.
     *
     * @param metric Name metric such as "response_time"
     * @param value
     * @param tags Tags to associate this value with, for example {from: 'serviceA', to: 'serviceB', method: 'login'}
     * @param namespace Define the metric namespace. Default: application
     * @param agg List of aggregations to be applied by the Telemetron. Ex: ['avg', 'p90', 'min']
     * @param aggFreq Aggregation frequency in seconds. One of: 10, 15, 30, 60 or 300
     * @param sample_rate Sampling rate (1-99)
     */
    public function put($metric, $value, $tags = array(), $namespace = 'application', $agg = array(), $aggFreq = 10, $sample_rate = 100) {
        $metricName = implode('.', array($this->prefix, $namespace, $metric));
        $flushData = array();
        $sample_rate_normalized = ($sample_rate) / 100;

        if(!empty($this->enviroment)) $tags = array_merge(array('environment' => $this->environment), $tags);
        if(!empty($this->platform)) $tags = array_merge(array('platform' => $this->platform), $tags);
        if(!empty($this->app)) $tags = array_merge(array('app' => $this->app), $tags);
        if(!empty($this->tags)) $tags = array_merge($tags, $this->tags);

        if(mt_rand() / mt_getrandmax() <= $sample_rate_normalized) {
            foreach ($tags as $tag => $data) {
                $flushData[] = "$tag=$data";
            }

            if (empty($flushData)) { return; }
            array_unshift($flushData,$metricName);

            $flushLine = implode(',', $flushData) . ' ' . $value . ' ' . time();

            if($agg) {
                $agg[] = $aggFreq;
                $flushLine .= ' ' . implode(',', $agg);

                if($sample_rate && $sample_rate < 100) {
                    $flushLine .= ' ' . $sample_rate;
                }
            }

            var_dump($flushLine);

            $this->putRaw($flushLine);
        }
    }

    /**
     * Adds raw metrics directly into the flush buffer. Use this method with caution.
     *
     * @param metricLines
     */
    private function putRaw($metricsLine) {
        $this->buffer[] = $metricsLine;
    }

    /**
     * Flush the metrics and closes the socket
     */
    public function send() {
        $this->flush();
        $this->close();
    }

    /**
     * Flushes the metrics to the Telemetron via UDP.
     */
    public function flush() {
        try {
            if(count($this->buffer) > 0) {
                $this->put('buffer', count($this->buffer), array('type' => 'flush_length'), $this->namespace, array('avg'));
                $message = implode('\n', $this->buffer);
                if($this->mock) {
                    echo 'Flushing metrics: ' . $message;
                }
                else {
                    fwrite($this->socket, $message);
                }
                $this->buffer = array();
            }
        } catch (Exception $e) {

        }
    }

    /**
     * Close the underlying socket and stop listening for data on it.
     */
    public function close() {
        try {
            fclose($this->socket);
        } catch (Exception $e) {

        }
    }
}
?>