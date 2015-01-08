<?php
/**
 * Author: Rottenwood
 * Date Created: 13.09.14 2:58
 */

namespace Rottenwood\BarchartBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManager;
use Rottenwood\BarchartBundle\Entity\IndicatorValue;
use Rottenwood\BarchartBundle\Entity\Price;
use Rottenwood\BarchartBundle\Entity\Signal;
use Rottenwood\BarchartBundle\Entity\Strategy;
use Rottenwood\BarchartBundle\Entity\Trade;
use Rottenwood\BarchartBundle\Entity\TradeAccount;

/**
 * Сервис анализа данных технических индикаторов
 * @date    22.09.2014
 * @package Rottenwood\BarchartBundle\Service
 */
class AnalizerService {

    const AVERAGE_SHORTTERM = 1;
    const AVERAGE_MIDDLETERM = 2;
    const AVERAGE_LONGTERM = 3;
    const AVERAGE_OVERALL = 4;

    private $em;
    private $config;
    private $lastProfit;

    public function __construct(ConfigService $configService, EntityManager $em) {
        $this->em = $em;
        $this->config = $configService->getConfig();
    }

    /**
     * Получение массива цен запрашиваемого инструмента
     * @param string $symbol    Название торгового символа
     * @param int    $priceFrom Запрос цен начиная с данного id
     * @param int    $bars      Запрашиваемое количество цен
     * @return array
     */
    public function getPrices($symbol, $priceFrom = 1, $bars = 0) {
        $symbolRepositoryName = "RottenwoodBarchartBundle:" . $symbol;

        // Если количество анализируемых цен не указано, берем их из конфига
        $bars = $bars ?: $this->getLimit();

        return $this->em->getRepository($symbolRepositoryName)->findPricesFromId($priceFrom, $bars);
    }

    /**
     * Запрос массива всех цен для заданного символа
     * @param $symbol
     * @return array
     */
    public function getAllPrices($symbol) {
        $symbolRepositoryName = "RottenwoodBarchartBundle:" . $symbol;

        return $this->em->getRepository($symbolRepositoryName)->findAll();
    }

    /**
     * Определение показаний индикатора по выбранной цене
     * @param Price  $price
     * @param string $indicator
     * @return mixed
     */
    public function indicator(Price $price, $indicator) {
        $indicatorName = 'get' . $indicator;

        return $price->$indicatorName();
    }

    /**
     * //TODO: нуждается в тестировании
     * Фильтрация последовательности цен, которые соответствуют серии одинаковых показателей индикатора
     * @param Price[] $prices
     * @param string  $indicator
     * @param int     $direction
     * @param int     $series
     * @return array
     */
    public function indicatorSeriesSignal($prices, $indicator, $direction, $series = 0) {
        $series = $series ?: $this->config['analizer']['series'];
        $indicatorName = 'get' . $indicator;

        $resultPrices = [];
        foreach ($prices as $key => $price) {
            $counter = 0;
            foreach (array_slice($prices, $key) as $priceForAnalize) {
                if ($priceForAnalize->$indicatorName() == $direction) {
                    $counter++;
                }
            }

            $resultPrices[] = ($counter != $series) ?: $price;
        }

        return $resultPrices;
    }

    /**
     * //TODO: нуждается в тестировании
     * Фильтрация массива цен на соответствие тренду
     * @param Price[] $prices
     * @param int     $trend
     * @return array
     */
    public function trendFilter($prices, $trend) {
        $resultPrices = [];
        foreach ($prices as $price) {
            /** @var Price $price */
            $resultPrices[] = ($price->getTrend() != $trend) ?: $price;
        }

        return $resultPrices;
    }

    /**
     * //TODO: нуждается в тестировании
     * Фильтрация массива цен на соответствие показателю усредненной группы индикаторов
     * @param Price[] $prices  Массив цен
     * @param int     $average 1 - shortTermAverage, 2 - middleTermAverage, 3 - longTermAverage, 4 - overall
     * @param int     $percent
     * @return array
     * @throws \Exception
     */
    public function averageFilter($prices, $average, $percent) {
        $averageIndicatorName = $this->getAverageFunctionName($average);

        if (!$averageIndicatorName) {
            throw new \Exception('Функция усреднения ' . $average . ' не найдена');
        }

        $resultPrices = [];
        foreach ($prices as $price) {
            $averageResult = $price->$averageIndicatorName();

            if ($percent >= 0 && $averageResult >= $percent || $percent < 0 && $averageResult < $percent) {
                $resultPrices[] = $price;
            }
        }

        return $resultPrices;
    }

    /**
     * //TODO: нуждается в тестировании
     * Фильтрация массива цен по заданному или среднему объему
     * @param Price[] $prices
     * @param int     $volume
     * @param bool    $lowerThan больше
     * @return array
     */
    public function volumeFilter($prices, $volume = 0, $lowerThan = false) {
        // Если не указан объем, расчет среднего объема для массива цен
        if (!$volume) {
            $volumeAverage = [];
            foreach ($prices as $priceObject) {
                $volumeAverage[] = $priceObject->getVolume();
            }

            $volume = array_sum($volumeAverage) / count($volumeAverage);
        }

        $resultPrices = [];
        foreach ($prices as $price) {
            if ($lowerThan) {
                if ($price->getVolume() <= $volume) {
                    $resultPrices[] = $price;
                }
            } else {
                if ($price->getVolume() >= $volume) {
                    $resultPrices[] = $price;
                }
            }
        }

        return $resultPrices;
    }

    /**
     * Бэктестинг стратеги
     * @param Strategy $strategy
     * @param int      $volume
     * @return array
     */
    public function testStrategy(Strategy $strategy, $volume = 1) {
        // Получение цен для анализа
        $prices = $this->getAllPrices($strategy->getSymbolName()[$strategy->getSymbol()]);

        // Массив сделок
        $trades = [];

        /** @var Price $priceObject */
        foreach ($prices as $priceKey => $priceObject) {
            $price = $priceObject->getPrice();

            // Сигналы
            foreach ($strategy->getSignals() as $signal) {
                $direction = $signal->getDirection();

                if ($this->indicatorsPassed($signal->getIndicatorValues(), $priceObject, $direction, $signal)) {
                    // Имитация открытия сделки, расчет ее результатов
                    $trade = new Trade();
                    $trade->setDirection($direction);
                    $trade->setOpen($price);
                    $trade->setOpenDate($priceObject->getDate());
                    $trade->setSymbol($strategy->getSymbol());
                    $trade->setVolume($volume);

                    $profit = 0;

                    /** @var Price $comparePriceObject */
                    foreach (array_slice($prices, $priceKey + 1) as $comparePriceKey => $comparePriceObject) {
                        $comparePrice = $comparePriceObject->getPrice();
                        $analizedTrade = $this->analyseProfit($priceObject, $comparePriceObject, $direction);
                        $analizedTradeHigh = $analizedTrade->getHigh();

                        if ($analizedTradeHigh > $trade->getHigh()) {
                            $trade->setHigh($analizedTradeHigh);
                        }

                        if ($analizedTradeHigh < $trade->getDrawdown()) {
                            $trade->setDrawdown($analizedTradeHigh);
                        }

                        // Критерии закрытия сделки
                        $percentProfit = $analizedTradeHigh / $price * 100;

                        // Расчет прибыли
                        if ($direction > 0) {
                            $profit = $comparePrice - $price;
                        } else {
                            $profit = $price - $comparePrice;
                        }

                        // Стоп в процентах
                        if ($signal->getStopLossPercent() && -$percentProfit >= $signal->getStopLossPercent()) {
                            $this->closeTrade($trade, $comparePrice, $comparePriceObject);
                            $profit = $price * $signal->getStopLossPercent() / 100;
                            break;
                        }

                        // Тейк в процентах
                        if ($signal->getTakeProfitPercent() && $percentProfit >= $signal->getTakeProfitPercent()) {
                            $this->closeTrade($trade, $comparePrice, $comparePriceObject);
                            $profit = $price * $signal->getTakeProfitPercent() / 100;
                            break;
                        }

                        // Стоп в пунктах
                        if ($signal->getStopLoss() && $signal->getStopLoss() >= $profit) {
                            $this->closeTrade($trade, $comparePrice, $comparePriceObject);
                            $profit = $signal->getStopLoss();
                            break;
                        }

                        // Тейк в пунктах
                        if ($signal->getTakeProfit() && $signal->getTakeProfit() <= $profit) {
                            $this->closeTrade($trade, $comparePrice, $comparePriceObject);
                            $profit = $signal->getTakeProfit();
                            break;
                        }
                    }

                    $trade->setProfit($profit);

                    $trades[] = $trade;
                }
            }
        }

        return $trades;
    }

    /**
     * Получение даты первой котировки по которой анализируется стратегия
     * @param Strategy $strategy
     * @return \DateTime|null
     */
    public function getFirstPriceDate(Strategy $strategy) {
        $prices = $this->getAllPrices($strategy->getSymbolName()[$strategy->getSymbol()]);
        $firstPrice = reset($prices);

        if ($firstPrice instanceof Price) {
            return $firstPrice->getDate();
        } else {
            return null;
        }
    }

    /**
     * Получение даты последней котировки по которой анализируется стратегия
     * @param Strategy $strategy
     * @return \DateTime|null
     */
    public function getLastPriceDate(Strategy $strategy) {
        $prices = $this->getAllPrices($strategy->getSymbolName()[$strategy->getSymbol()]);
        $lastPrice = end($prices);

        if ($lastPrice instanceof Price) {
            return $lastPrice->getDate();
        } else {
            return null;
        }
    }

    /**
     * Расчет прибыли по серии сделок в процентах
     * @param array $trades
     * @return float|int
     */
    public function calculatePercentProfit(array $trades) {
        $percentProfit = 100;
        foreach ($trades as $trade) {
            if ($trade instanceof Trade) {
                $price = $trade->getOpen();
                $profit = $trade->getProfit();
                $percentProfit = $profit / $price * 100 + $percentProfit;
            }
        }

        return round($percentProfit - 100, 2);
    }

    /**
     * Получение имен для усредненных групп индикаторов
     * @return array
     */
    public function getAverageNames() {
        return [
            self::AVERAGE_SHORTTERM  => 'Средний показатель краткосрочных индикаторов',
            self::AVERAGE_MIDDLETERM => 'Средний показатель среднесрочных индикаторов',
            self::AVERAGE_LONGTERM   => 'Средний показатель долгосрочных индикаторов',
            self::AVERAGE_OVERALL    => 'Средний показатель всех индикаторов',
        ];
    }

    /**
     * Получение имени геттера для усредненных групп индикаторов
     * @param integer $average
     * @return array
     */
    private function getAverageFunctionName($average) {
        $averageFunctionNames = [
            self::AVERAGE_SHORTTERM  => 'getShorttermAverage',
            self::AVERAGE_MIDDLETERM => 'getMiddletermAverage',
            self::AVERAGE_LONGTERM   => 'getLongtermAverage',
            self::AVERAGE_OVERALL    => 'getOverall',
        ];

        return $averageFunctionNames[$average];
    }

    /**
     * Расчет результата торговой позиции
     * @param Price $priceObject
     * @param Price $priceCompareObject
     * @param int   $direction
     * @return Trade
     */
    private function analyseProfit(Price $priceObject, Price $priceCompareObject, $direction) {

        $openPrice = $priceObject->getPrice();
        $closePrice = $priceCompareObject->getPrice();

        if ($direction == Signal::DIRECTION_BUY) {
            $profit = $closePrice - $openPrice;
        } else {
            $profit = $openPrice - $closePrice;
        }

        // Сохранение значения для дальнейшего использования
        $this->lastProfit = $profit;

        $trade = new Trade();
        $trade->setHigh($profit);
        $trade->setOpen($openPrice);
        $trade->setClose($closePrice);

        return $trade;
    }

    /**
     * Определение горизонта для анализа
     * @return integer
     */
    private function getLimit() {
        $limitWeeks = $this->config['analizer']['horizon']['weeks'];
        $limitDays = $this->config['analizer']['horizon']['days'];
        $limitHours = $this->config['analizer']['horizon']['hours'];

        return $limitWeeks * 5 * 19 + $limitDays * 19 + $limitHours;
    }

    /**
     * Проверка на срабатывание всех индикаторов сигнала
     * @param Collection $indicatorValues
     * @param Price      $priceObject
     * @param int        $direction
     * @param Signal     $signal
     * @return bool
     */
    private function indicatorsPassed(Collection $indicatorValues, Price $priceObject, $direction, Signal $signal) {
        $indicatorsPassed = 0;
        foreach ($indicatorValues->toArray() as $indicatorValueObject) {
            /** @var IndicatorValue $indicatorValueObject */
            $indicator = $indicatorValueObject->getIndicator();
            $indicatorMethod = 'get' . $indicator->getStrategyMethod();

            $priceIndicatorValue = $priceObject->$indicatorMethod();
            $indicatorValue = $indicatorValueObject->getValue();

            if (($direction == $signal::DIRECTION_BUY && $priceIndicatorValue >= $indicatorValue)
                || ($direction == $signal::DIRECTION_SELL && $priceIndicatorValue <= $indicatorValue)
            ) {
                $indicatorsPassed++;
            }
        }

        return $indicatorsPassed == count($indicatorValues);
    }

    /**
     * Закрытие сделки
     * @param Trade $trade
     * @param float $comparePrice
     * @param Price $comparePriceObject
     */
    private function closeTrade(Trade $trade, $comparePrice, Price $comparePriceObject) {
        $trade->setClose($comparePrice);
        $trade->setCloseDate($comparePriceObject->getDate());
    }
}
