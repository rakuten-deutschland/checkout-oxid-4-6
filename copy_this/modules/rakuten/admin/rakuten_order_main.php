<?php
/**
 * Copyright (c) 2012, Rakuten Deutschland GmbH. All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the Rakuten Deutschland GmbH nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
 * PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL RAKUTEN DEUTSCHLAND GMBH BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING
 * IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

class rakuten_order_main extends rakuten_order_main_parent /* extends order_main */
{
    /**
     * Saves main orders configuration parameters.
     *
     * @return string
     */
    public function save()
    {
        $soxId = $this->getEditObjectId();
        $aParams    = oxConfig::getParameter( "editval" );

        // shopid
        $sShopID = oxSession::getVar( "actshop" );
        $aParams['oxorder__oxshopid'] = $sShopID;

        $oOrder = oxNew( "oxorder" );
        if ( $soxId != "-1") {
            $oOrder->load( $soxId);
        } else {
            $aParams['oxorder__oxid'] = null;
        }

        if ($oOrder->oxorder__oxpaymenttype->value != 'rakuten') {
            //change payment
            $sPayId = oxConfig::getParameter( "setPayment");
            if ($sPayId != $oOrder->oxorder__oxpaymenttype->value) {
                $aParams['oxorder__oxpaymenttype'] = $sPayId;
            }
        }
        $oOrder->assign( $aParams);

        $aDynvalues = oxConfig::getParameter( "dynvalue" );
        if ( isset( $aDynvalues ) ) {
            // #411 Dodger
            $oPayment = oxNew( "oxuserpayment" );
            $oPayment->load( $oOrder->oxorder__oxpaymentid->value);
            $oPayment->oxuserpayments__oxvalue->setValue(oxUtils::getInstance()->assignValuesToText( $aDynvalues));
            $oPayment->save();
        }

        if ($oOrder->oxorder__oxpaymenttype->value != 'rakuten') {
        //change delivery set
            $sDelSetId = oxConfig::getParameter( "setDelSet");
            if ($sDelSetId != $oOrder->oxorder__oxdeltype->value) {
                $oOrder->oxorder__oxpaymenttype->setValue( "oxempty" );
                $oOrder->setDelivery( $sDelSetId );
            } else {
                // keeps old delivery cost
                $oOrder->reloadDelivery( false );
            }
        }

        // keeps old discount
        $oOrder->reloadDiscount( false );

        $oOrder->recalculateOrder();

        // set oxid if inserted
        $this->setEditObjectId( $oOrder->getId() );
    }
}