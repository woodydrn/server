<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OC\Http\Client;

use OC\Net\IpAddressClassifier;
use OCP\Http\Client\LocalServerException;
use Psr\Http\Message\RequestInterface;

class DnsPinMiddleware {

	public function __construct(
		private NegativeDnsCache $negativeDnsCache,
		private IpAddressClassifier $ipAddressClassifier,
	) {
	}

	/**
	 * Fetch soa record for a target
	 */
	private function soaRecord(string $target): ?array {
		$labels = explode('.', $target);

		$top = count($labels) >= 2 ? array_pop($labels) : '';
		$second = array_pop($labels);

		$hostname = $second . '.' . $top;
		$responses = $this->dnsGetRecord($hostname, DNS_SOA);

		if ($responses === false || count($responses) === 0) {
			return null;
		}

		return reset($responses);
	}

	private function dnsResolve(string $target, int $recursionCount) : array {
		if ($recursionCount >= 10) {
			return [];
		}

		$recursionCount++;
		$targetIps = [];

		$soaDnsEntry = $this->soaRecord($target);
		$dnsNegativeTtl = $soaDnsEntry['minimum-ttl'] ?? null;
		$canHaveCnameRecord = true;

		$dnsTypes = \defined('AF_INET6') || @inet_pton('::1')
			? [DNS_A, DNS_AAAA, DNS_CNAME]
			: [DNS_A, DNS_CNAME];
		foreach ($dnsTypes as $dnsType) {
			if ($canHaveCnameRecord === false && $dnsType === DNS_CNAME) {
				continue;
			}

			if ($this->negativeDnsCache->isNegativeCached($target, $dnsType)) {
				continue;
			}

			$dnsResponses = $this->dnsGetRecord($target, $dnsType);
			if ($dnsResponses !== false && count($dnsResponses) > 0) {
				foreach ($dnsResponses as $dnsResponse) {
					if (isset($dnsResponse['ip'])) {
						$targetIps[] = $dnsResponse['ip'];
						$canHaveCnameRecord = false;
					} elseif (isset($dnsResponse['ipv6'])) {
						$targetIps[] = $dnsResponse['ipv6'];
						$canHaveCnameRecord = false;
					} elseif (isset($dnsResponse['target']) && $canHaveCnameRecord) {
						$targetIps = array_merge($targetIps, $this->dnsResolve($dnsResponse['target'], $recursionCount));
					}
				}
			} elseif ($dnsNegativeTtl !== null) {
				$this->negativeDnsCache->setNegativeCacheForDnsType($target, $dnsType, $dnsNegativeTtl);
			}
		}

		return $targetIps;
	}

	/**
	 * Wrapper for dns_get_record
	 */
	protected function dnsGetRecord(string $hostname, int $type): array|false {
		return \dns_get_record($hostname, $type);
	}

	public function addDnsPinning(): callable {
		return function (callable $handler) {
			return function (
				RequestInterface $request,
				array $options,
			) use ($handler) {
				if ($options['nextcloud']['allow_local_address'] === true) {
					return $handler($request, $options);
				}

				$hostName = $request->getUri()->getHost();
				$port = $request->getUri()->getPort();

				$ports = [
					'80',
					'443',
				];

				if ($port !== null) {
					$ports[] = (string)$port;
				}

				$targetIps = $this->dnsResolve(idn_to_utf8($hostName), 0);

				if (empty($targetIps)) {
					throw new LocalServerException('No DNS record found for ' . $hostName);
				}

				$curlResolves = [];

				foreach ($ports as $port) {
					$curlResolves["$hostName:$port"] = [];

					foreach ($targetIps as $ip) {
						if ($this->ipAddressClassifier->isLocalAddress($ip)) {
							// TODO: continue with all non-local IPs?
							throw new LocalServerException('Host "' . $ip . '" (' . $hostName . ':' . $port . ') violates local access rules');
						}
						$curlResolves["$hostName:$port"][] = $ip;
					}
				}

				// Coalesce the per-host:port ips back into a comma separated list
				foreach ($curlResolves as $hostport => $ips) {
					$options['curl'][CURLOPT_RESOLVE][] = "$hostport:" . implode(',', $ips);
				}

				return $handler($request, $options);
			};
		};
	}
}
