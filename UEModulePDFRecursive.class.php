<?php
/**
 * UniversalExport Recursive PDF Module extension for BlueSpice
 *
 * Enables MediaWiki to export pages into PDF format.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3.
 *
 * This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 *
 * This file is part of BlueSpice MediaWiki
 * For further information visit http://bluespice.com
 *
 * @author     Robert Vogel <vogel@hallowelt.com>
 * @package    BlueSpice_Extensions
 * @subpackage UEModulePDFRecursive
 * @copyright  Copyright (C) 2016 Hallo Welt! GmbH, All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GPL-3.0-only
 * @filesource
 */

/* Changelog
 * v1.20.0
 * - Initial release
 */

/**
 * Base class for UniversalExport PDF Module extension
 * @package BlueSpice_Extensions
 * @subpackage UEModulePDFRecursive
 */
class UEModulePDFRecursive extends BsExtensionMW {

	/**
	 * Initialization of UEModulePDFRecursive extension
	 */
	protected function initExt() {
		// Hooks
		$this->setHook( 'SkinTemplateOutputPageBeforeExec' );
		$this->setHook( 'BSUEModulePDFBeforeAddingContent' );
	}

	/**
	 * Hook handler to add menu
	 * @param SkinTemplate &$skin
	 * @param QuickTemplate &$template
	 * @return bool Always true to keep hook running
	 */
	public function onSkinTemplateOutputPageBeforeExec( &$skin, &$template ) {
		if ( $skin->getTitle()->isContentPage() === false ) {
			return true;
		}
		if ( !$skin->getTitle()->userCan( 'uemodulepdfrecursive-export' ) ) {
			return true;
		}

		$template->data['bs_export_menu'][] = $this->buildContentAction();

		return true;
	}

	/**
	 * builds the contentAction array for the current page
	 *
	 * @return array contentAction
	 * @throws MWException
	 */
	private function buildContentAction() {
		$currentQueryParams = $this->getRequest()->getValues();
		if ( isset( $currentQueryParams['title'] ) ) {
			$title = $currentQueryParams['title'];
		} else {
			$title = '';
		}
		$specialPageParameter = BsCore::sanitize( $title, '', BsPARAMTYPE::STRING );
		$specialPage = SpecialPage::getTitleFor( 'UniversalExport', $specialPageParameter );
		if ( isset( $currentQueryParams['title'] ) ) {
			unset( $currentQueryParams['title'] );
		}
		$currentQueryParams['ue[module]'] = 'pdf';
		$currentQueryParams['ue[recursive]'] = '1';

		return [
			'id' => 'pdf-recursive',
			'href' => $specialPage->getLinkUrl( $currentQueryParams ),
			'title' => wfMessage( 'bs-uemodulepdfrecursive-widgetlink-recursive-title' )->text(),
			'text' => wfMessage( 'bs-uemodulepdfrecursive-widgetlink-recursive-text' )->text(),
			'class' => 'bs-ue-export-link',
			'iconClass' => 'icon-file-pdf bs-ue-export-link'
		];
	}

	/**
	 *
	 * @param array &$template
	 * @param array &$contents
	 * @param \stdClass $caller
	 * @param array $params
	 * @return bool Always true to keep hook running
	 */
	public function onBSUEModulePDFBeforeAddingContent( &$template, &$contents, $caller,
		$params = [] ) {
		global $wgRequest;
		$params = $caller->aParams;
		if ( empty( $params ) ) {
			$ueParams = $wgRequest->getArray( 'ue' );
			$params['recursive'] = isset( $ueParams['recursive'] ) ? $ueParams['recursive'] : 0;
		}

		if ( $params['recursive'] == 0 ) {
			return true;
		}

		$newDOM = new DOMDocument();
		$pageDOM = $contents['content'][0];
		$pageDOM->setAttribute(
			'class',
			$pageDOM->getAttribute( 'class' ) . ' bs-source-page'
		);
		$node = $newDOM->importNode( $pageDOM, true );
		$linkMap = [];
		$rootTitle = \Title::newFromText( $template['title-element']->nodeValue );
		if ( $pageDOM->getElementsByTagName( 'a' )->item(0)->getAttribute( 'id' ) === '' ) {
			$pageDOM->getElementsByTagName( 'a' )->item(0)->setAttribute(
				'id',
				md5( 'bs-ue-' . $rootTitle->getPrefixedDBKey() )
			);
		}

		$linkMap[ $template['title-element']->nodeValue ]
			= $pageDOM->getElementsByTagName( 'a' )->item( 0 )->getAttribute( 'id' );

		$newDOM->appendChild( $node );
		$links = $pageDOM->getElementsByTagName( 'a' );
		$pages = [];
		foreach ( $links as $link ) {
			$class = $link->getAttribute( 'class' );
			$classes = explode( ' ', $class );
			$excludeClasses = [ 'new', 'external' ];
			// HINT: http://stackoverflow.com/questions/7542694/in-array-multiple-values
			if ( count( array_intersect( $classes, $excludeClasses ) ) > 0 ) {
				continue;
			}

			$linkTitle = $link->getAttribute( 'title' );
			if ( empty( $linkTitle ) || empty( $link->nodeValue ) ) {
				continue;
			}

			$title = Title::newFromText( $linkTitle );
			if ( $title == null || !$title->canExist() ) {
				continue;
			}

			// Avoid double export
			if ( in_array( $title->getPrefixedText(), $pages ) ) {
				continue;
			}

			if ( !$title->userCan( 'read' ) ) {
				continue;
			}

			$pageProvider = new BsPDFPageProvider();
			$pageProviderContent = $pageProvider->getPage( [
				'article-id' => $title->getArticleID(),
				'title' => $title->getFullText()
			] );

			if ( !isset( $pageProviderContent['dom'] ) ) {
				continue;
			}
			$DOMDocument = $pageProviderContent['dom'];

			$documentLinks = $DOMDocument->getElementsByTagName( 'a' );

			// set array index from $linkMap
			if( $documentLinks->item( 0 ) instanceof DOMElement ) {
				if ( $documentLinks->item(0)->getAttribute( 'id' ) === '' ) {
					$documentLinks->item(0)->setAttribute(
						'id',
						md5( 'bs-ue-' . $title->getPrefixedDBKey() )
					);
				}
				$linkMap[ $title->getPrefixedText() ] = $documentLinks->item( 0 )->getAttribute( 'id' );
			}

			$contents['content'][] = $DOMDocument->documentElement;
			$pages[] = $title->getPrefixedText();
		}

		$documentToc = $this->makeToc( $linkMap );
		foreach ( $contents['content'] as $oDom ) {
			$this->rewriteLinks( $oDom, $linkMap );
		}

		array_unshift( $contents['content'], $documentToc->documentElement );
		\Hooks::run( 'UEModulePDFRecursiveAfterContent', [ $this, &$contents ] );

		return true;
	}

	/**
	 *
	 * @param DOMNode &$domNode
	 * @param array $linkMap
	 */
	protected function rewriteLinks( &$domNode, $linkMap ) {
		$anchors = $domNode->getElementsByTagName( 'a' );
		foreach ( $anchors as $anchor ) {
			$href = null;
			$linkTitle = $anchor->getAttribute( 'data-bs-title' );
			if ( $linkTitle ) {
				$pathBasename = $linkTitle;
			} else {
				$href  = $anchor->getAttribute( 'href' );

				$class = $anchor->getAttribute( 'class' );

				if ( empty( $href ) ) {
					// Jumplink targets
					continue;
				}

				$classes = explode( ' ', $class );

				if ( in_array( 'external', $classes ) ) {
					continue;
				}

				$parsedHref = parse_url( $href );
				if ( !isset( $parsedHref['path'] ) ) {
					continue;
				}

				$parser = new \BlueSpice\Utility\UrlTitleParser(
					$href, MediaWiki\MediaWikiServices::getInstance()->getMainConfig(), true
				);
				$parsedTitle = $parser->parseTitle();
				if ( !$parsedTitle instanceof Title ) {
					continue;
				}
				$pathBasename = $parsedTitle->getPrefixedText();
			}

			if ( !isset( $linkMap[$pathBasename] ) ) {
				$pathBasename = "";
				// Do we have a mapping?
				/*
				 * The following logic is an alternative way of creating internal links
				 * in case of poorly split up URLs like mentioned above
				 */
				if ( filter_var( $href, FILTER_VALIDATE_URL ) ) {
					$hrefDecoded = urldecode( $href );
					foreach ( $linkMap as $linkKey => $linkValue ) {
						if ( strpos( str_replace( '_', ' ', $hrefDecoded ), $linkKey ) ) {
							$pathBasename = $linkKey;
						}
					}

					if ( empty( $pathBasename ) || strlen( $pathBasename ) <= 0 ) {
						continue;
					}
				}
			}

			if ( !$pathBasename || !isset( $linkMap[$pathBasename] ) ) {
				$anchor->removeAttribute( 'href' );
			} else {
				$anchor->setAttribute( 'href', '#' . $linkMap[$pathBasename] );
			}
		}
	}

	/**
	 * @param array $linkMap
	 * @return DOMDocument
	 */
	protected function makeTOC( $linkMap ) {
		$tocDocument = new DOMDocument();

		$tocWrapper = $tocDocument->createElement( 'div' );
		$tocWrapper->setAttribute( 'class', 'bs-page-content bs-page-toc' );

		$tocHeading = $tocDocument->createElement( 'h1' );
		$tocHeading->appendChild( $tocDocument->createTextNode( wfMessage( 'toc' )->text() ) );

		$tocWrapper->appendChild( $tocHeading );

		$tocList = $tocDocument->createElement( 'ul' );
		$tocList->setAttribute( 'class', 'toc' );

		$count = 1;
		foreach ( $linkMap as $linkname => $linkHref ) {
			$liClass = 'toclevel-1';
			if ( $count === 1 ) {
				$liClass .= ' bs-source-page';
			}
			$tocListItem = $tocList->appendChild( $tocDocument->createElement( 'li' ) );
			$tocListItem->setAttribute( 'class', $liClass );

			$tocListItemLink = $tocListItem->appendChild( $tocDocument->createElement( 'a' ) );
			$tocListItemLink->setAttribute( 'href', '#' . $linkHref );
			$tocListItemLink->setAttribute( 'class', 'toc-link' );

			$tocLinkSpanNumber = $tocListItemLink->appendChild( $tocDocument->createElement( 'span' ) );
			$tocLinkSpanNumber->setAttribute( 'class', 'tocnumber' );
			$tocLinkSpanNumber->appendChild( $tocDocument->createTextNode( $count . '.' ) );

			$tocListSpanText = $tocListItemLink->appendChild( $tocDocument->createElement( 'span' ) );
			$tocListSpanText->setAttribute( 'class', 'toctext' );
			$tocListSpanText->appendChild( $tocDocument->createTextNode( ' ' . $linkname ) );

			$count++;
		}
		$tocWrapper->appendChild( $tocList );
		$tocDocument->appendChild( $tocWrapper );

		return $tocDocument;
	}
}
