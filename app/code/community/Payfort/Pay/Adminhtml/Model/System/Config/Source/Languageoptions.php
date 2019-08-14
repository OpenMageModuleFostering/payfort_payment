<?php

class Payfort_Pay_Adminhtml_Model_System_Config_Source_Languageoptions {
    /*     * */

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray() {
        return array(
            array('value' => 'en_US', 'label' => Mage::helper('adminhtml')->__('en_US')),
            array('value' => 'ar_SA', 'label' => Mage::helper('adminhtml')->__('ar_SA')),
        );
    }

}
