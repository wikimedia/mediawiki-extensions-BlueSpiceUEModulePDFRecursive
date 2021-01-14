<?php

namespace BlueSpice\UEModulePDFRecursive\Hook\BSUEModulePDFBeforeAddingContent;

use BlueSpice\UEModulePDF\Hook\BSUEModulePDFBeforeAddingContent;
use BlueSpice\Utility\UrlTitleParser;
use BsPDFPageProvider;
use BsUniversalExportHelper;
use DOMDocument;
use DOMElement;
use Title;

class AddRecursivePages extends BSUEModulePDFBeforeAddingContent {

	/**
	 *
	 * @return bool
	 */
	protected function skipProcessing() {
		if ( !isset( $this->getParams()['recursive'] ) || $this->getParams()['recursive'] == 0 ) {
			return true;
		}
		return false;
	}

	/**
	 *
	 * @return bool
	 */
	protected function doProcess() {
		$newDOM = new DOMDocument();
		$pageDOM = $this->contents['content'][0];
		$pageDOM->setAttribute(
			'class',
			$pageDOM->getAttribute( 'class' ) . ' bs-source-page'
		);
		$node = $newDOM->importNode( $pageDOM, true );

		$includedTitleMap = [];
		$rootTitle = \Title::newFromText( $this->template['title-element']->nodeValue );
		if ( $pageDOM->getElementsByTagName( 'a' )->item( 0 )->getAttribute( 'id' ) === '' ) {
			$pageDOM->getElementsByTagName( 'a' )->item( 0 )->setAttribute(
				'id',
				md5( $rootTitle->getPrefixedText() )
			);
		}

		$includedTitleMap[ $this->template['title-element']->nodeValue ]
			= $pageDOM->getElementsByTagName( 'a' )->item( 0 )->getAttribute( 'id' );

		$newDOM->appendChild( $node );

		$includedTitles = $this->findLinkedTitles( $pageDOM );
		if ( count( $includedTitles ) < 1 ) {
			return true;
		}

		$titleMap = array_merge(
			$includedTitleMap,
			$this->generateIncludedTitlesMap( $includedTitles )
		);

		$this->setIncludedTitlesId( $includedTitles, $titleMap );
		$this->addIncludedTitlesContent( $includedTitles, $titleMap, $this->contents['content'] );

		foreach ( $this->contents['content'] as $oDom ) {
			$this->rewriteLinks( $oDom, $titleMap );
		}

		$this->makeBookmarks( $this->template, $includedTitles );

		$documentToc = $this->makeToc( $titleMap );
		array_unshift( $this->contents['content'], $documentToc->documentElement );

		$this->getServices()->getHookContainer()->run(
			'UEModulePDFRecursiveAfterContent',
			[
				$this,
				&$this->contents
			]
		);
	}

	/**
	 *
	 * @return array
	 */
	private function getParams() {
		$ueParams = $this->caller->aParams;

		if ( empty( $ueParams ) ) {
			$requestParams = $this->getContext()->getRequest()->getArray( 'ue' );
			$ueParams['recursive'] = isset( $requestParams['recursive'] )
				? $requestParams['recursive']
				: 0;
		}
		return $ueParams;
	}

	/**
	 *
	 * @param array $includedTitles
	 * @param array $includedTitleMap
	 * @param array &$contents
	 */
	private function addIncludedTitlesContent( $includedTitles, $includedTitleMap, &$contents ) {
		foreach ( $includedTitles as $name => $content ) {
			$contents[] = $content['dom']->documentElement;
		}
	}

	/**
	 *
	 * @param array $includedTitles
	 * @return array
	 */
	private function generateIncludedTitlesMap( $includedTitles ) {
		$includedTitleMap = [];

		foreach ( $includedTitles as $name => $content ) {
			$includedTitleMap = array_merge( $includedTitleMap, [ $name => md5( $name ) ] );
		}

		return $includedTitleMap;
	}

	/**
	 *
	 * @param array &$includedTitles
	 * @param array $includedTitleMap
	 */
	private function setIncludedTitlesId( &$includedTitles, $includedTitleMap ) {
		foreach ( $includedTitles as $name => $content ) {
			// set array index from $includedTitleMap
			$documentLinks = $content['dom']->getElementsByTagName( 'a' );
			if ( $documentLinks->item( 0 ) instanceof DOMElement ) {
				$documentLinks->item( 0 )->setAttribute(
					'id',
					$includedTitleMap[$name]
				);
			}
		}
	}

	/**
	 *
	 * @param DOMDocument $rootTitleDom
	 * @return array
	 */
	private function findLinkedTitles( $rootTitleDom ) {
		$linkdedTitles = [];

		$links = $rootTitleDom->getElementsByTagName( 'a' );

		foreach ( $links as $link ) {
			$class = $link->getAttribute( 'class' );
			$classes = explode( ' ', $class );
			$excludeClasses = [ 'new', 'external' ];

			// HINT: http://stackoverflow.com/questions/7542694/in-array-multiple-values
			if ( count( array_intersect( $classes, $excludeClasses ) ) > 0 ) {
				continue;
			}

			$linkTitle = $link->getAttribute( 'data-bs-title' );
			if ( empty( $linkTitle ) || empty( $link->nodeValue ) ) {
				continue;
			}

			$title = Title::newFromText( $linkTitle );
			if ( $title == null || !$title->canExist() ) {
				continue;
			}

			// Avoid double export
			if ( in_array( $title->getPrefixedText(), $linkdedTitles ) ) {
				continue;
			}

			if ( !$title->userCan( 'read' ) ) {
				continue;
			}

			$pageProvider = new BsPDFPageProvider();
			$pageContent = $pageProvider->getPage( [
				'article-id' => $title->getArticleID(),
				'title' => $title->getFullText()
			] );

			if ( !isset( $pageContent['dom'] ) ) {
				continue;
			}

			$linkdedTitles = array_merge(
				$linkdedTitles,
				[
					$title->getPrefixedText() => $pageContent
				]
			);
		}

		ksort( $linkdedTitles );

		return $linkdedTitles;
	}

	/**
	 *
	 * @param array &$template
	 * @param array $includedTitles
	 */
	private function makeBookmarks( &$template, $includedTitles ) {
		foreach ( $includedTitles as $name => $content ) {
			$bookmarkNode = BsUniversalExportHelper::getBookmarkElementForPageDOM( $content['dom'] );
			$bookmarkNode = $template['dom']->importNode( $bookmarkNode, true );

			$template['bookmarks-element']->appendChild( $bookmarkNode );
		}
	}

	/**
	 *
	 * @param DOMNode &$domNode
	 * @param array $linkMap
	 */
	private function rewriteLinks( &$domNode, $linkMap ) {
		$anchors = $domNode->getElementsByTagName( 'a' );
		foreach ( $anchors as $anchor ) {
			$linkTitle = $anchor->getAttribute( 'data-bs-title' );
			$href  = $anchor->getAttribute( 'href' );

			if ( $linkTitle ) {
				$pathBasename = str_replace( '_', ' ', $linkTitle );

				$parsedHref = parse_url( $href );

				if ( isset( $linkMap[$pathBasename] ) && isset( $parsedHref['fragment'] ) ) {
					$linkMap[$pathBasename] = $linkMap[$pathBasename] . '-'
						. md5( $parsedHref['fragment'] );
				}
			} else {
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

				$parser = new UrlTitleParser(
					$href, $this->getServices()->getMainConfig(), true
				);
				$parsedTitle = $parser->parseTitle();

				if ( !$parsedTitle instanceof Title ) {
					continue;
				}

				$pathBasename = $parsedTitle->getPrefixedText();
			}

			if ( !isset( $linkMap[$pathBasename] ) ) {
				continue;
			}

			$anchor->setAttribute( 'href', '#' . $linkMap[$pathBasename] );
		}
	}

	/**
	 * @param array $linkMap
	 * @return DOMDocument
	 */
	private function makeTOC( $linkMap ) {
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
