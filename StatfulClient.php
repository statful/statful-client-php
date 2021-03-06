<?php

/**
 * The UDP Client for Statful.
 *
 * @constructor
 */
class StatfulClient {

    private $host;
    private $port;
    private $prefix;
    private $socket;
    private $buffer;
    private $environment;
    private $platform;
    private $app;
    protected $namespace = 'application';
    protected $sampleRate = 100;
    protected $aggFreq = 10;
    protected $tags = array();
    public $mock = false;
    public $persistent = true;

    /**
     * Initialize the Statful udp client
     * @param string host Statful host address
     * @port string port Statful host port
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
            if($this->persistent)
                $this->socket = pfsockopen("udp://$this->host", $this->port, $errno, $errstr);
            else
                $this->socket = fsockopen("udp://$this->host", $this->port, $errno, $errstr);
        }
        catch (Exception $e) {

        }

        if (!$this->socket && !$this->mock) { return; }
    }

    /**
     * Sets global metric namespace. Default: application
     * @param $namespace
     */
    public function _setNamespace($namespace) {
        $this->namespace = $namespace;
    }

    /**
     * Sets global sampling rate (1-99)
     * @param $sampleRate
     */
    public function _setSampleRate($sampleRate) {
        $this->sampleRate = $sampleRate;
    }

    /**
     * Sets global aggregation frequency in seconds. One of: 10, 15, 30, 60 or 300
     * @param $aggFreq
     */
    public function _setAggFreq($aggFreq) {
        $this->aggFreq = $aggFreq;
    }

    /**
     * Sets global tags to associate this value with, for example {from: 'serviceA', to: 'serviceB', method: 'login'}
     * @param $tags
     */
    public function _setTags($tags) {
        $this->tags = $tags;
    }

    /**
     * Sends a timing metric
     *
     * @param name Name of the counter. Ex: response_time
     * @param value
     * @param tags Tags to associate this value with, for example {from: 'serviceA', to: 'serviceB', method: 'login'}
     * @param namespace Define the metric namespace. Default: application
     * @param agg List of aggregations to be applied by the Statful. Ex: ['avg', 'p90', 'min']
     * @param aggFreq Aggregation frequency in seconds. One of: 10, 15, 30, 60 or 300
     * @param sampleRate Sampling rate (1-99)
     */
    public function time($name, $value, $tags = array(), $namespace = 'application', $agg = null, $aggFreq = null, $sampleRate = null) {
        if(is_null($agg)) $agg = array('avg', 'p90', 'count');
        $type = array('unit' => 'ms');
        if(!$value || $value < 0) $value = 0;
        if(is_null($aggFreq)) $aggFreq = $this->aggFreq;
        if(is_null($sampleRate)) $sampleRate = $this->sampleRate;

        $this->put('timer.'.$name, $value, array_merge($type, $tags), $namespace, $agg, $aggFreq, $sampleRate);
    }

    /**
     * Increments a counter
     *
     * @param name Name of the counter. Ex: transactions
     * @param value
     * @param tags Tags to associate this value with, for example {type: 'purchase_order'}
     * @param namespace Define the metric namespace. Default: application
     * @param agg List of aggregations to be applied by the Statful. Ex: ['avg', 'p90', 'min']
     * @param aggFreq Aggregation frequency in seconds. One of: 10, 15, 30, 60 or 300
     * @param sampleRate Sampling rate (1-99)
     */
    public function inc($name, $value, $tags = array(), $namespace = 'application', $agg = null, $aggFreq = null, $sampleRate = null) {
        if(is_null($agg)) $agg = array('sum', 'count');
        if(!$value || $value < 0) $value = 0;
        if(is_null($aggFreq)) $aggFreq = $this->aggFreq;
        if(is_null($sampleRate)) $sampleRate = $this->sampleRate;

        $this->put('counter.'.$name, $value, $tags, $namespace, $agg, $aggFreq, $sampleRate);
    }

    /**
     * Adds a Gauge
     * @param name Name of the Gauge. Ex: current_sessions
     * @param value
     * @param tags Tags to associate this value with, for example {page: 'overview'}
     * @param namespace Define the metric namespace. Default: application
     * @param agg List of aggregations to be applied by the Statful. Ex: ['avg', 'p90', 'min']
     * @param aggFreq Aggregation frequency in seconds. One of: 10, 15, 30, 60 or 300
     * @param sampleRate Sampling rate (1-99)
     */
    public function gauge($name, $value, $tags = array(), $namespace = 'application', $agg = null, $aggFreq = null, $sampleRate = null) {
        if(is_null($agg)) $agg = array('last');
        if(!$value || $value < 0) $value = 0;
        if(is_null($aggFreq)) $aggFreq = $this->aggFreq;
        if(is_null($sampleRate)) $sampleRate = $this->sampleRate;

        $this->put('counter'.$name, $value, $tags, $namespace, $agg, $aggFreq, $sampleRate);
    }

    /**
     * Adds a new metric to the in-memory buffer.
     *
     * @param metric Name metric such as "response_time"
     * @param value
     * @param tags Tags to associate this value with, for example {from: 'serviceA', to: 'serviceB', method: 'login'}
     * @param namespace Define the metric namespace. Default: application
     * @param agg List of aggregations to be applied by the Statful. Ex: ['avg', 'p90', 'min']
     * @param aggFreq Aggregation frequency in seconds. One of: 10, 15, 30, 60 or 300
     * @param sampleRate Sampling rate (1-99)
     */
    public function put($metric, $value, $tags = array(), $namespace = null, $agg = array(), $aggFreq = null, $sampleRate = null) {
        if(is_null($namespace)) $namespace = $this->namespace;
        if(is_null($aggFreq)) $aggFreq = $this->aggFreq;
        if(is_null($sampleRate)) $sampleRate = $this->sampleRate;

        try {
            $metricName = implode('.', array($this->prefix, $namespace, $metric));
            $flushData = array();
            $sample_rate_normalized = ($sampleRate) / 100;

            if(!empty($this->environment)) $tags = array_merge(array('environment' => $this->environment), $tags);
            if(!empty($this->platform)) $tags = array_merge(array('platform' => $this->platform), $tags);
            if(!empty($this->app)) $tags = array_merge(array('app' => $this->app), $tags);
            if(!empty($this->tags)) $tags = array_merge($this->tags, $tags);

            if(mt_rand() / mt_getrandmax() <= $sample_rate_normalized) {
                foreach ($tags as $tag => $data) {
                    $flushData[] = "$tag=$data";
                }

                if (empty($flushData)) { return; }
                array_unshift($flushData,$metricName);

                $flushLine = implode(',', $flushData) . ' ' . $value . ' ' . time();

                if(is_array($agg) && count($agg) > 0 && $aggFreq > 0) {
                    $agg[] = $aggFreq;
                    $flushLine .= ' ' . implode(',', $agg);

                    if($sampleRate && $sampleRate < 100) {
                        $flushLine .= ' ' . $sampleRate;
                    }
                }

                $this->putRaw($flushLine);
            }
        }
        catch (Exception $e) {

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
        if(!$this->persistent) $this->close();
    }

    /**
     * Flushes the metrics to the Statful via UDP.
     */
    public function flush() {
        try {
            if(count($this->buffer) > 0) {
                if(count($this->buffer) > 1) {
                    $this->put('buffer', count($this->buffer), array('type' => 'flush_length'), $this->namespace, array('avg'));
                    $message = implode("\n", $this->buffer);
                }
                else {
                    $message = $this->buffer[0];
                }

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
