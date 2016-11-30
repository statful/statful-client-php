# statful-client-php

PHP Client for Statful

# How to use

```
#!php

        $metricNamespace = 'business';
        $metricName = 'wager';
        $metricTags = array(
            'transaction' => 'create',
            'platform' => 'desktop',
            'action' => 'bet',
        );
        $metricValue = 100;

        $client = new StatfulClient('127.0.0.1', '2014', 'business', 'production');
        $client->put($metricName, $metricValue, $metricTags, $metricNamespace);
        $client->send();

```