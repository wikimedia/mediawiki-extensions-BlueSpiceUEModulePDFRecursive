<?php

namespace BlueSpice\UEModulePDFRecursive\Hook\ChameleonSkinTemplateOutputPageBeforeExec;

use BlueSpice\Hook\ChameleonSkinTemplateOutputPageBeforeExec;
use BlueSpice\UniversalExport\ModuleFactory;

class AddWidget extends ChameleonSkinTemplateOutputPageBeforeExec {
	/**
	 *
	 * @return bool
	 */
	protected function skipProcessing() {
		if ( !$this->getServices()->getSpecialPageFactory()->exists( 'UniversalExport' ) ) {
			return true;
		}
		$userCan = $this->getServices()->getPermissionManager()->userCan(
			'uemodulepdfrecursive-export',
			$this->skin->getUser(),
			$this->skin->getTitle()
		);
		if ( !$userCan ) {
			return true;
		}
		return false;
	}

	protected function doProcess() {
		/** @var ModuleFactory $moduleFactory */
		$moduleFactory = $this->getServices()->getService(
			'BSUniversalExportModuleFactory'
		);
		$module = $moduleFactory->newFromName( 'pdf' );

		$contentActions = [
			'id' => 'pdf-recursive',
			'href' => $module->getExportLink( $this->getContext()->getRequest(),  [
				'ue[recursive]' => '1',
			] ),
			'title' => $this->msg( 'bs-uemodulepdfrecursive-widgetlink-recursive-title' )->plain(),
			'text' => $this->msg( 'bs-uemodulepdfrecursive-widgetlink-recursive-text' )->plain(),
			'class' => 'bs-ue-export-link',
			'iconClass' => 'icon-file-pdf bs-ue-export-link'
		];

		$this->template->data['bs_export_menu'][] = $contentActions;
	}

}
