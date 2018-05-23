<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\CurrencyExchangeBundle\Tests\Unit\Service;

use ONGR\CurrencyExchangeBundle\Service\CurrencyRatesService;
use ONGR\CurrencyExchangeBundle\Document\CurrencyDocument;
use ONGR\ElasticsearchBundle\ORM\Manager;
use ONGR\ElasticsearchBundle\ORM\Repository;

/**
 * This class holds unit tests for currency rates service.
 */
class CurrencyRatesServiceTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Repository|\PHPUnit_Framework_MockObject_MockObject
     */
    private $repositoryMock;

    /**
     * @var Manager|\PHPUnit_Framework_MockObject_MockObject
     */
    private $esManagerMock;

    /**
     * @var array
     */
    private $esRatesBackup;

    /**
     * @var array
     */
    private $ratesFixture = [
        'DOP' => '58.3638',
        'DZD' => '111.7223',
        'EEK' => '16.0508',
        'EGP' => '9.4839',
        'ETB' => '26.1282',
        'EUR' => '1.0000',
        'LTL' => '3.4516',
        'FJD' => '2.5182',
        'FKP' => '0.8561',
        'UGX' => '3475.3425',
        'USD' => '1.3766',
        'UYU' => '29.6077',
        'UZS' => '2988.6321',
        'VEF' => '8.6630',
        'VND' => '29105.6858',
    ];

    /**
     * Before a test method is run, a template method called setUp() is invoked.
     */
    public function setUp()
    {
        $this->esRatesResult = [];
        foreach ($this->ratesFixture as $currency => $rate) {
            $this->esRatesBackup[0]['rates'][] = [
                'name' => $currency,
                'value' => $rate,
            ];
        }

        $searchMock = $this->getMock('ONGR\ElasticsearchBundle\DSL\Search');

        $searchMock->expects($this->any())->method('addSort')->will($this->returnSelf());

        $this->repositoryMock = $this->getMockBuilder('ONGR\ElasticsearchBundle\ORM\Repository')
            ->disableOriginalConstructor()
            ->setMethods(['createSearch', 'execute', 'createDocument'])
            ->getMock();

        $this->repositoryMock->expects($this->any())->method('createSearch')->willReturn($searchMock);
        $this->repositoryMock->expects($this->any())->method('createDocument')->willReturn(new CurrencyDocument());

        $this->esManagerMock = $this->getMockBuilder('ONGR\ElasticsearchBundle\ORM\Manager')
            ->setMethods(['getRepository', 'persist', 'commit'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->esManagerMock->expects($this->any())->method('getRepository')->willReturn($this->repositoryMock);
        $this->esManagerMock->expects($this->any())->method('createSearch')->willReturn($searchMock);
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|\Psr\Log\LoggerInterface
     */
    private function getLogger()
    {
        return $this->getMock('Psr\Log\LoggerInterface');
    }

    /**
     * @param string     $base  Base currency name.
     * @param null|array $rates Currency rates.
     *
     * @return \PHPUnit_Framework_MockObject_MockObject|\ONGR\CurrencyExchangeBundle\Currency\CurrencyDriverInterface
     */
    private function getDriverMock($base, $rates = null)
    {
        $mock = $this->getMock('ONGR\CurrencyExchangeBundle\Currency\CurrencyDriverInterface');
        $mock->expects($this->any())->method('getRates')->will($this->returnValue($rates));
        $mock->expects($this->any())->method('getDefaultCurrencyName')->will($this->returnValue($base));

        return $mock;
    }

    /**
     * @param mixed $value
     *
     * @return \PHPUnit_Framework_MockObject_MockObject|\Stash\Interfaces\ItemInterface
     */
    private function getCacheItem($value)
    {
        $mock = $this->getMock('Stash\Interfaces\ItemInterface');

        if ($value) {
            $mock->expects($this->any())->method('get')->will($this->returnValue($value));
        }

        return $mock;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|\Doctrine\Common\Cache\Cache
     */
    private function getCache()
    {
        $mock = $this->getMock('Doctrine\Common\Cache\Cache');

        return $mock;
    }

    /**
     * Test if we return correct base currency.
     */
    public function testGetBaseCurrency()
    {
        $service = new CurrencyRatesService($this->getDriverMock('EUR'), $this->esManagerMock, $this->getCache());
        $service->setLogger($this->getLogger());
        $this->assertEquals('EUR', $service->getBaseCurrency());
    }

    /**
     * Test if we are able to retrieve rates from cache.
     */
    public function testGetRatesFromCache()
    {
        $this->repositoryMock->expects($this->any())->method('execute')->willReturn($this->esRatesResult);

        $cache = $this->getCache();
        $cache->expects($this->once())->method('fetch')->with('ongr_currency')->will(
            $this->returnValue($this->ratesFixture)
        );
        $loader = $this->getDriverMock('EUR');
        $loader->expects($this->never())->method('getRates');

        $service = new CurrencyRatesService($loader, $this->esManagerMock, $cache);
        $service->setLogger($this->getLogger());

        $this->assertEquals($this->ratesFixture, $service->getRates());

        // Test local cache.
        $this->assertEquals($this->ratesFixture, $service->getRates());
    }

    /**
     * Test if we are able to retrieve rates from cache.
     */
    public function testGetRatesFromDriver()
    {
        $this->repositoryMock->expects($this->any())->method('execute')->willReturn([]);

        $cache = $this->getCache();
        $cache->expects($this->once())->method('save')->with('ongr_currency', $this->ratesFixture);
        $cache->expects($this->any())->method('fetch')->with('ongr_currency')->will(
            $this->returnValue(false)
        );
        $loader = $this->getDriverMock('EUR', $this->ratesFixture);

        $service = new CurrencyRatesService($loader, $this->esManagerMock, $cache);
        $service->setLogger($this->getLogger());

        $this->assertEquals($this->ratesFixture, $service->getRates());

        // Test local cache.
        $this->assertEquals($this->ratesFixture, $service->getRates());
    }

    /**
     * Exception when rates are not loaded.
     *
     * @expectedException \ONGR\CurrencyExchangeBundle\Exception\RatesNotLoadedException
     */
    public function testException()
    {
        $this->repositoryMock->expects($this->any())->method('execute')->willReturn([]);
        $cache = $this->getCache();
        $cache->expects($this->any())->method('fetch')->with('ongr_currency')->willReturn(false);

        $service = new CurrencyRatesService($this->getDriverMock('EUR', []), $this->esManagerMock, $cache);
        $service->setLogger($this->getLogger());
        $service->getRates();
    }
}
