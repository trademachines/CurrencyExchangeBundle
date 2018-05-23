<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\CurrencyExchangeBundle\Service;

use Doctrine\Common\Cache\Cache;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use ONGR\CurrencyExchangeBundle\Currency\CurrencyDriverInterface;
use ONGR\CurrencyExchangeBundle\Document\CurrencyDocument;
use ONGR\CurrencyExchangeBundle\Document\RatesObject;
use ONGR\CurrencyExchangeBundle\Exception\RatesNotLoadedException;
use ONGR\ElasticsearchBundle\DSL\Query\MatchAllQuery;
use ONGR\ElasticsearchBundle\DSL\Sort\Sort;
use ONGR\ElasticsearchBundle\ORM\Manager;
use ONGR\ElasticsearchBundle\ORM\Repository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * This class provides currency rates.
 */
class CurrencyRatesService implements LoggerAwareInterface
{
    /**
     * @var string
     */
    private static $CACHE_KEY = 'ongr_currency';

    use LoggerAwareTrait;

    /**
     * @var null|array
     */
    public $rates = null;

    /**
     * @var CurrencyDriverInterface
     */
    private $driver;

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var Manager
     */
    private $manager;

    /**
     * @param CurrencyDriverInterface $driver  Currency exchange driver.
     * @param Manager                 $manager ES Manager.
     * @param Cache                   $cache   Cache.
     */
    public function __construct(
        CurrencyDriverInterface $driver,
        Manager $manager,
        Cache $cache
    ) {
        $this->driver  = $driver;
        $this->manager = $manager;
        $this->cache   = $cache;
    }

    /**
     * This method returns exchange rates.
     *
     * @throws RatesNotLoadedException
     * @return array
     */
    public function getRates()
    {
        if (isset($this->rates)) {
            return $this->rates;
        }

        $this->rates = $this->getCachedRates();
        if (false !== $this->rates) {
            return $this->rates;
        }

        $this->rates = $this->getRatesFromBackup();
        if (isset($this->rates)) {
            return $this->rates;
        }

        $this->rates = $this->reloadRates();
        if (isset($this->rates)) {
            return $this->rates;
        }
        throw new RatesNotLoadedException('Currency rates are not loaded and could not be loaded on demand');
    }

    /**
     * Returns currency rates from ES.
     *
     * @return array
     */
    private function getRatesFromBackup()
    {
        $rates = [];
        $repository = $this->manager->getRepository('ONGRCurrencyExchangeBundle:CurrencyDocument');
        $search = $repository->createSearch();
        $sort = new Sort('created_at', Sort::ORDER_DESC);
        $search->addSort($sort);
        $query = new MatchAllQuery();
        $search->addQuery($query);
        $search->setSize(1);
        try {
            $results = $repository->execute($search, Repository::RESULTS_ARRAY);
        } catch (Missing404Exception $e) {
            $this->logger && $this->logger->notice('Failed to execute query. Please check ES configuration');

            return null;
        }

        if (count($results)) {
            foreach ($results[0]['rates'] as $data) {
                $rates[$data['name']] = $data['value'];
            }
            $this->logger && $this->logger->notice('Rates returned from ES. Cache updated.');
            $this->updateRatesCache($rates);

            return $rates;
        }

        return null;
    }

    /**
     * Update rates in cache.
     *
     * @param array $rates
     */
    private function updateRatesCache($rates)
    {
        $this->cache->save(static::$CACHE_KEY, $rates);
    }

    /**
     * @return mixed|false
     */
    private function getCachedRates()
    {
        return $this->cache->fetch(static::$CACHE_KEY);
    }

    /**
     * Reloads rates using given driver.
     *
     * @return array
     */
    public function reloadRates()
    {
        $esRates = [];
        $this->rates = $this->driver->getRates();
        $repository = $this->manager->getRepository('ONGRCurrencyExchangeBundle:CurrencyDocument');
        /** @var CurrencyDocument $document */
        $document = $repository->createDocument();
        $document->setCreatedAt(new \DateTime());

        if ($this->rates) {
            foreach ($this->rates as $name => $value) {
                $ratesObject = new RatesObject();
                $ratesObject->setName($name);
                $ratesObject->setValue($value);
                $esRates[] = $ratesObject;
            }
            $document->rates = $esRates;
            $this->manager->persist($document);
            $this->manager->commit();
            $this->updateRatesCache($this->rates);

            return $this->rates;
        }
        $this->logger && $this->logger->notice('Failed to retrieve currency rates from provider.');

        return null;
    }

    /**
     * Returns actual base currency name.
     *
     * @return string
     */
    public function getBaseCurrency()
    {
        return $this->driver->getDefaultCurrencyName();
    }
}
