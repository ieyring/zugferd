<?php

namespace horstoeko\zugferd;

/**
 * Class representing the auto-calculator for amounts of a document
 * builded by document build
 */
class ZugferdDocumentBuilderWithCalculator extends ZugferdDocumentBuilder
{
    /**
     * @inheritDoc
     *
     * @return void
     */
    protected function OnBeforeGetContent()
    {
        $this->Calculate();
    }

    /**
     * Complete calculation of all amounts
     *
     * @return ZugferdDocumentBuilderWithCalculator
     */
    public function calculate(): ZugferdDocumentBuilderWithCalculator
    {
        $this->CalculatePositionLineSummations();
        $this->CalculateDocumentSummation();

        return $this;
    }

    /**
     * Calculate all line summations
     *
     * @return void
     */
    public function calculatePositionLineSummations()
    {
        $lines = $this->car($this->headerSupplyChainTradeTransaction, 'getIncludedSupplyChainTradeLineItem') ?? [];

        foreach ($lines as $line) {
            $this->CalculatePositionLineSummation($line);
        }
    }

    /**
     * Calculate a single summations
     *
     * @param object $line
     * @return ZugferdDocumentBuilderWithCalculator
     */
    public function calculatePositionLineSummation(object $line): ZugferdDocumentBuilderWithCalculator
    {
        $positionsettlement = $this->car($line, "getSpecifiedLineTradeSettlement");

        $grossPriceType = $this->cbpar($line, "getSpecifiedLineTradeAgreement.getGrossPriceProductTradePrice");
        if (!is_null($grossPriceType)) {
            $grossPriceAllowanceCharges = $this->ea($this->cbpar($grossPriceType, "getAppliedTradeAllowanceCharge"));
            $grossPriceAllowanceChargesSum = 0.0;

            foreach ($grossPriceAllowanceCharges as $grossPriceAllowanceCharge) {
                $grossPriceAllowanceChargeBasisAmountType = $this->cbpar($grossPriceAllowanceCharge, "getBasisAmount");
                $grossPriceAllowanceChargeCalculationPercentType = $this->cbpar($grossPriceAllowanceCharge, "getCalculationPercent");
                $grossPriceAllowanceChargeActualAmountType = $this->cbpar($grossPriceAllowanceCharge, "getActualAmount");
                $grossPriceAllowanceChargeIsCharge = (bool) $this->cbpar($grossPriceAllowanceCharge, "getChargeIndicator.getIndicator", false);

                if (
                    is_null($grossPriceAllowanceChargeBasisAmountType) &&
                    is_null($grossPriceAllowanceChargeCalculationPercentType) &&
                    is_null($grossPriceAllowanceChargeActualAmountType)
                ) {
                    continue;
                }

                if (!is_null($grossPriceAllowanceChargeBasisAmountType) && !is_null($grossPriceAllowanceChargeCalculationPercentType)) {
                    $grossPriceAllowanceChargeActualAmount = round(
                        $this->cbpar($grossPriceAllowanceChargeBasisAmountType, "value", 0.0) *
                            $this->cbpar($grossPriceAllowanceChargeCalculationPercentType, "value", 0.0) / 100,
                        2
                    );
                } else {
                    $grossPriceAllowanceChargeActualAmount =
                        $this->cbpar($grossPriceAllowanceChargeActualAmountType, "value", 0.0);
                }

                $grossPriceAllowanceChargesSum =
                    $grossPriceAllowanceChargesSum + ($grossPriceAllowanceChargeIsCharge == false ? $grossPriceAllowanceChargeActualAmount : -$grossPriceAllowanceChargeActualAmount);
            }

            $grossPriceAmount = $this->cbpar($grossPriceType, "getChargeAmount.value", 0.0);
            $netPrice = $grossPriceAmount - $grossPriceAllowanceChargesSum;

            $netPriceType = $this->objectHelper->GetTradePriceType($netPrice);
            $positionagreement = $this->car($line, "getSpecifiedLineTradeAgreement");
            $this->tc($positionagreement, "setNetPriceProductTradePrice", $netPriceType);
        }

        $billedQuantity = (float) $this->cbpar($line, "getSpecifiedLineTradeDelivery.getBilledQuantity.value", 0.0);
        $netPrice = (float) $this->cbpar($line, "getSpecifiedLineTradeAgreement.getNetPriceProductTradePrice.getChargeAmount.value", 0.0);
        $positionAllowanceCharges = (array) $this->cbpar($line, "getSpecifiedLineTradeSettlement.getSpecifiedTradeAllowanceCharge", []);
        $positionAllowanceChargesSum = 0.0;

        foreach ($positionAllowanceCharges as $positionAllowanceCharge) {
            $isCharge = (bool) $this->cbpar($positionAllowanceCharge, "getChargeIndicator.getIndicator", false);
            $actualAmount = (float) $this->cbpar($positionAllowanceCharge, "getActualAmount.value", 0.0);
            $positionAllowanceChargesSum = $positionAllowanceChargesSum + ($isCharge == false ? -$actualAmount : $actualAmount);
        }

        $summation =
            $this->objectHelper->GetTradeSettlementLineMonetarySummationType(
                round($netPrice * $billedQuantity + $positionAllowanceChargesSum, 2),
                round($positionAllowanceChargesSum, 2)
            );

            $this->tc($positionsettlement, "setSpecifiedTradeSettlementLineMonetarySummation", $summation);

        return $this;
    }

    public function calculateDocumentSummation()
    {
        $lines = $this->car($this->headerSupplyChainTradeTransaction, 'getIncludedSupplyChainTradeLineItem') ?? [];

        $lineTotalAmount = 0.0;
        $vatSumGrouped = [];
        $docAllowanceSum = 0.0;
        $docChargeSum = 0.0;
        $docNetAmount = 0.0;
        $docVatSum = 0.0;

        foreach ($lines as $line) {
            $lineAmount = $this->cbpar($line, "getSpecifiedLineTradeSettlement.getSpecifiedTradeSettlementLineMonetarySummation.getLineTotalAmount.value", 0.0);
            $lineTotalAmount = $lineTotalAmount + $lineAmount;
            $lineTaxes = $this->ea($this->cbpar($line, "getSpecifiedLineTradeSettlement.getApplicableTradeTax", []));

            foreach ($lineTaxes as $lineTax) {
                $vatCategory = (string) $this->cbpar($lineTax, "getCategoryCode.value", "");
                $vatType = (string) $this->cbpar($lineTax, "getTypeCode.value", "");
                $vatPercent = (float) $this->cbpar($lineTax, "getRateApplicablePercent.value", 0.0);

                $vatGroupId = md5($vatCategory . $vatType . $vatPercent);

                $vatSumGrouped[$vatGroupId] = isset($vatSumGrouped[$vatGroupId]) ? $vatSumGrouped[$vatGroupId] : [$vatCategory, $vatType, $vatPercent, 0, 0];
                $vatSumGrouped[$vatGroupId][4] = $vatSumGrouped[$vatGroupId][4] + $lineAmount;
                $vatSumGrouped[$vatGroupId][3] = round($vatSumGrouped[$vatGroupId][4] * ($vatPercent / 100.0), 2);
            }
        }

        $docAllowanceCharges = $this->ea($this->cbpar($this->headerTradeSettlement, 'getSpecifiedTradeAllowanceCharge', []));

        foreach ($docAllowanceCharges as $docAllowanceCharge) {
            $actualAmount = $this->cbpar($docAllowanceCharge, "getActualAmount.value");
            $vatCategory = (string) $this->cbpar($docAllowanceCharge, "getCategoryTradeTax.getCategoryCode.value", "");
            $vatType = (string) $this->cbpar($docAllowanceCharge, "getCategoryTradeTax.getTypeCode.value", "");
            $vatPercent = (float) $this->cbpar($docAllowanceCharge, "getCategoryTradeTax.getRateApplicablePercent.value", 0.0);
            $chargeindicator = (bool) $this->cbpar($docAllowanceCharge, "getChargeIndicator.getIndicator", false);

            $vatGroupId = md5($vatCategory . $vatType . $vatPercent);

            $vatSumGrouped[$vatGroupId] = isset($vatSumGrouped[$vatGroupId]) ? $vatSumGrouped[$vatGroupId] : [$vatCategory, $vatType, $vatPercent, 0, 0];
            $vatSumGrouped[$vatGroupId][4] = $vatSumGrouped[$vatGroupId][4] + ($chargeindicator === true ? $actualAmount : -$actualAmount);
            $vatSumGrouped[$vatGroupId][3] = round($vatSumGrouped[$vatGroupId][4] * ($vatPercent / 100.0), 2);

            $docAllowanceSum = $docAllowanceSum + ($chargeindicator === true ? 0.0 : $actualAmount);
            $docChargeSum = $docChargeSum + ($chargeindicator === true ? $actualAmount : 0.0);
        }

        $docLogisticCharges = $this->ea($this->cbpar($this->headerTradeSettlement, 'getSpecifiedLogisticsServiceCharge', []));

        foreach ($docLogisticCharges as $docLogisticCharge) {
            $actualAmount = $this->cbpar($docLogisticCharge, "getAppliedAmount.value");
            $docChargeSum = $docChargeSum + $actualAmount;
        }

        foreach ($vatSumGrouped as $vatSumGroupedItemKey => $vatSumGroupedItem) {
            $docNetAmount = $docNetAmount + $vatSumGroupedItem[4];
            $docVatSum = $docVatSum + $vatSumGroupedItem[3];
            $this->addDocumentTax(
                $vatSumGroupedItem[0],
                $vatSumGroupedItem[1],
                $vatSumGroupedItem[4],
                $vatSumGroupedItem[3],
                $vatSumGroupedItem[2]
            );
        }

        $summation = $this->car($this->headerTradeSettlement, "getSpecifiedTradeSettlementHeaderMonetarySummation");

        $totalPrepaidAmount = $this->cbpar($summation, "getTotalPrepaidAmount.value", 0.0);

        $this->setDocumentSummation(
            round($docNetAmount + $docVatSum, 2),
            round($docNetAmount + $docVatSum - $totalPrepaidAmount, 2),
            round($lineTotalAmount, 2),
            round($docChargeSum, 2),
            round($docAllowanceSum, 2),
            round($docNetAmount, 2),
            round($docVatSum, 2),
            null,
            $totalPrepaidAmount
        );
    }

    /**
     * Shortcut method for $this->objectHelper->TryCallByPathAndReturn
     *
     * @param object|null $instance
     * @param string $methods
     * @param mixed $defaultValue
     * @return mixed
     */
    private function cbpar(?object $instance, string $methods, $defaultValue = null)
    {
        return $this->objectHelper->TryCallByPathAndReturn($instance, $methods) ?? $defaultValue;
    }

    /**
     * Shortcut method for $this->objectHelper->TryCallAndReturn
     *
     * @param object $instance
     * @param string $method
     * @param mixed $defaultValue
     * @return mixed
     */
    private function car(object $instance, string $method, $defaultValue = null)
    {
        return $this->objectHelper->TryCallAndReturn($instance, $method) ?? $defaultValue;
    }

    /**
     * Shortcut method for $this->objectHelper->TryCall
     *
     * @param object $instance
     * @param string $method
     * @param mixed $value
     * @return mixed
     */
    private function tc(object $instance, string $method, $value)
    {
        return $this->objectHelper->TryCall($instance, $method, $value);
    }


    /**
     * Shortcut method for $this->objectHelper->EnsureArray
     *
     * @param mixed $value
     * @return array
     */
    private function ea($value): array
    {
        return $this->objectHelper->EnsureArray($value);
    }
}
