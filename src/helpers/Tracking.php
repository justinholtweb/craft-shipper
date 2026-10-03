<?php

namespace justinholtweb\shipper\helpers;

/**
 * Public tracking URLs, built from the carrier name or code ShipStation reports.
 *
 * ShipStation is inconsistent about which it sends — `carrier` on a shipnotify is the friendly
 * name ("FedEx"), while the REST API uses codes ("fedex", "stamps_com") — so matching is done on
 * a normalised form of whatever arrives.
 */
abstract class Tracking
{
    /**
     * Normalised carrier key => URL template with `%s` for the tracking number.
     */
    private const URLS = [
        'ups' => 'https://www.ups.com/track?loc=en_US&tracknum=%s',
        'usps' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
        'stampscom' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
        'endicia' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
        'fedex' => 'https://www.fedex.com/fedextrack/?trknbr=%s',
        'dhlexpress' => 'https://www.dhl.com/en/express/tracking.html?AWB=%s',
        'dhlglobalmail' => 'https://www.dhl.com/en/express/tracking.html?AWB=%s',
        'dhlecommerce' => 'https://webtrack.dhlecs.com/orders?trackingNumber=%s',
        'dhl' => 'https://www.dhl.com/en/express/tracking.html?AWB=%s',
        'ontrac' => 'https://www.ontrac.com/tracking/?number=%s',
        'lasership' => 'https://www.lasership.com/track/%s',
        'canadapost' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=%s',
        'purolator' => 'https://www.purolator.com/en/shipping/tracker?pin=%s',
        'royalmail' => 'https://www.royalmail.com/track-your-item#/tracking-results/%s',
        'australiapost' => 'https://auspost.com.au/mypost/track/details/%s',
        'newzealandpost' => 'https://www.nzpost.co.nz/tools/tracking/item/%s',
        'globegistics' => 'https://tracking.globegistics.com/?trackingnumber=%s',
        'apc' => 'https://apctrk.com/?tracking=%s',
        'firstmile' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
        'seko' => 'https://www.sekologistics.com/en/tracking/?id=%s',
        'gso' => 'https://www.gso.com/Tracking?TrackingNumbers=%s',
        'amazonshipping' => 'https://track.amazon.com/tracking/%s',
    ];

    /**
     * Reduce a carrier name or code to a comparable key: "DHL Express" and "dhl_express" both
     * become "dhlexpress".
     */
    public static function normalize(?string $carrier): string
    {
        if ($carrier === null) {
            return '';
        }

        return strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $carrier));
    }

    /**
     * A tracking URL, or null when the carrier is unknown to us. Returning null is deliberate —
     * a link that 404s is worse than plain text.
     */
    public static function url(?string $carrier, ?string $trackingNumber): ?string
    {
        if ($trackingNumber === null || trim($trackingNumber) === '') {
            return null;
        }

        $key = self::normalize($carrier);

        if ($key === '') {
            return null;
        }

        $template = self::URLS[$key] ?? null;

        if ($template === null) {
            // "UPS Ground Saver" and the like: fall back to the longest known key it starts with.
            $matched = '';

            foreach (self::URLS as $candidate => $candidateTemplate) {
                if (strlen($candidate) > strlen($matched) && str_starts_with($key, $candidate)) {
                    $matched = $candidate;
                    $template = $candidateTemplate;
                }
            }
        }

        if ($template === null) {
            return null;
        }

        return sprintf($template, rawurlencode(trim($trackingNumber)));
    }

    /**
     * Every carrier key Shipper can build a URL for, for documentation and tests.
     *
     * @return string[]
     */
    public static function knownCarriers(): array
    {
        return array_keys(self::URLS);
    }
}
