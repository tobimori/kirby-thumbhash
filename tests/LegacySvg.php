<?php

namespace tobimori\Tests;

/**
 * The SVG placeholder of kirby-thumbhash 2.1.1, the plugin must keep its output
 */
class LegacySvg
{
	/**
	 * @param array{uri: string, width: int, height: int, transparent: bool} $image
	 */
	public static function uri(array $image, float $blurRadius): string
	{
		if ($blurRadius === 0.0) {
			return $image['uri'];
		}

		$svgHeight = number_format($image['height'], 2, '.', '');
		$svgWidth = number_format($image['width'], 2, '.', '');

		$alphaFilter = '';
		if (!$image['transparent']) {
			$alphaFilter = <<<EOD
				<feComponentTransfer>
				    <feFuncA type="discrete" tableValues="1 1"></feFuncA>
				</feComponentTransfer>
				EOD;
		}

		$data = <<<EOD
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$svgWidth} {$svgHeight}">
			  <filter id="b" color-interpolation-filters="sRGB">
			    <feGaussianBlur stdDeviation="{$blurRadius}"></feGaussianBlur>
			    {$alphaFilter}
			  </filter>
			  <image filter="url(#b)" x="0" y="0" width="100%" height="100%" href="{$image['uri']}"></image>
			</svg>
			EOD;

		$data = preg_replace(['/\s+/', '/> </'], [' ', '><'], $data) ?? $data;
		$data = str_replace(['%2F', '%3A', '%3D'], ['/', ':', '='], rawurlencode($data));

		return "data:image/svg+xml;charset=utf-8,{$data}";
	}
}
