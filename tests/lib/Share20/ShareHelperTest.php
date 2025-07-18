<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Test\Share20;

use OC\Share20\ShareHelper;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Share\IManager;
use Test\TestCase;

class ShareHelperTest extends TestCase {
	/** @var IManager|\PHPUnit\Framework\MockObject\MockObject */
	private $manager;

	/** @var ShareHelper */
	private $helper;

	protected function setUp(): void {
		parent::setUp();

		$this->manager = $this->createMock(IManager::class);

		$this->helper = new ShareHelper($this->manager);
	}

	public static function dataGetPathsForAccessList(): array {
		return [
			[[], [], false, [], [], false, [
				'users' => [],
				'remotes' => [],
			]],
			[['user1', 'user2'], ['user1' => 'foo', 'user2' => 'bar'], true, [], [], false, [
				'users' => ['user1' => 'foo', 'user2' => 'bar'],
				'remotes' => [],
			]],
			[[], [], false, ['remote1', 'remote2'], ['remote1' => 'qwe', 'remote2' => 'rtz'], true, [
				'users' => [],
				'remotes' => ['remote1' => 'qwe', 'remote2' => 'rtz'],
			]],
			[['user1', 'user2'], ['user1' => 'foo', 'user2' => 'bar'], true, ['remote1', 'remote2'], ['remote1' => 'qwe', 'remote2' => 'rtz'], true, [
				'users' => ['user1' => 'foo', 'user2' => 'bar'],
				'remotes' => ['remote1' => 'qwe', 'remote2' => 'rtz'],
			]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('dataGetPathsForAccessList')]
	public function testGetPathsForAccessList(array $userList, array $userMap, $resolveUsers, array $remoteList, array $remoteMap, $resolveRemotes, array $expected): void {
		$this->manager->expects($this->once())
			->method('getAccessList')
			->willReturn([
				'users' => $userList,
				'remote' => $remoteList,
			]);

		/** @var Node|\PHPUnit\Framework\MockObject\MockObject $node */
		$node = $this->createMock(Node::class);
		/** @var ShareHelper|\PHPUnit\Framework\MockObject\MockObject $helper */
		$helper = $this->getMockBuilder(ShareHelper::class)
			->setConstructorArgs([$this->manager])
			->onlyMethods(['getPathsForUsers', 'getPathsForRemotes'])
			->getMock();

		$helper->expects($resolveUsers ? $this->once() : $this->never())
			->method('getPathsForUsers')
			->with($node, $userList)
			->willReturn($userMap);

		$helper->expects($resolveRemotes ? $this->once() : $this->never())
			->method('getPathsForRemotes')
			->with($node, $remoteList)
			->willReturn($remoteMap);

		$this->assertSame($expected, $helper->getPathsForAccessList($node));
	}

	public static function dataGetPathsForUsers(): array {
		return [
			[[], [23 => 'TwentyThree', 42 => 'FortyTwo'], []],
			[
				[
					'test1' => ['node_id' => 16, 'node_path' => '/foo'],
					'test2' => ['node_id' => 23, 'node_path' => '/bar'],
					'test3' => ['node_id' => 42, 'node_path' => '/cat'],
					'test4' => ['node_id' => 48, 'node_path' => '/dog'],
				],
				[16 => 'SixTeen', 23 => 'TwentyThree', 42 => 'FortyTwo'],
				[
					'test1' => '/foo/TwentyThree/FortyTwo',
					'test2' => '/bar/FortyTwo',
					'test3' => '/cat',
				],
			],
		];
	}

	/**
	 *
	 * @param array $users
	 * @param array $nodes
	 * @param array $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataGetPathsForUsers')]
	public function testGetPathsForUsers(array $users, array $nodes, array $expected): void {
		$lastNode = null;
		foreach ($nodes as $nodeId => $nodeName) {
			/** @var Node|\PHPUnit\Framework\MockObject\MockObject $node */
			$node = $this->createMock(Node::class);
			$node->expects($this->any())
				->method('getId')
				->willReturn($nodeId);
			$node->expects($this->any())
				->method('getName')
				->willReturn($nodeName);
			if ($lastNode === null) {
				$node->expects($this->any())
					->method('getParent')
					->willThrowException(new NotFoundException());
			} else {
				$node->expects($this->any())
					->method('getParent')
					->willReturn($lastNode);
			}
			$lastNode = $node;
		}

		$this->assertEquals($expected, self::invokePrivate($this->helper, 'getPathsForUsers', [$lastNode, $users]));
	}

	public static function dataGetPathsForRemotes(): array {
		return [
			[[], [23 => 'TwentyThree', 42 => 'FortyTwo'], []],
			[
				[
					'test1' => ['node_id' => 16, 'token' => 't1'],
					'test2' => ['node_id' => 23, 'token' => 't2'],
					'test3' => ['node_id' => 42, 'token' => 't3'],
					'test4' => ['node_id' => 48, 'token' => 't4'],
				],
				[
					16 => '/admin/files/SixTeen',
					23 => '/admin/files/SixTeen/TwentyThree',
					42 => '/admin/files/SixTeen/TwentyThree/FortyTwo',
				],
				[
					'test1' => ['token' => 't1', 'node_path' => '/SixTeen'],
					'test2' => ['token' => 't2', 'node_path' => '/SixTeen/TwentyThree'],
					'test3' => ['token' => 't3', 'node_path' => '/SixTeen/TwentyThree/FortyTwo'],
				],
			],
		];
	}

	/**
	 *
	 * @param array $remotes
	 * @param array $nodes
	 * @param array $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataGetPathsForRemotes')]
	public function testGetPathsForRemotes(array $remotes, array $nodes, array $expected): void {
		$lastNode = null;
		foreach ($nodes as $nodeId => $nodePath) {
			/** @var Node|\PHPUnit\Framework\MockObject\MockObject $node */
			$node = $this->createMock(Node::class);
			$node->expects($this->any())
				->method('getId')
				->willReturn($nodeId);
			$node->expects($this->any())
				->method('getPath')
				->willReturn($nodePath);
			if ($lastNode === null) {
				$node->expects($this->any())
					->method('getParent')
					->willThrowException(new NotFoundException());
			} else {
				$node->expects($this->any())
					->method('getParent')
					->willReturn($lastNode);
			}
			$lastNode = $node;
		}

		$this->assertEquals($expected, self::invokePrivate($this->helper, 'getPathsForRemotes', [$lastNode, $remotes]));
	}

	public static function dataGetMountedPath(): array {
		return [
			['/admin/files/foobar', '/foobar'],
			['/admin/files/foo/bar', '/foo/bar'],
		];
	}

	/**
	 * @param string $path
	 * @param string $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataGetMountedPath')]
	public function testGetMountedPath($path, $expected): void {
		/** @var Node|\PHPUnit\Framework\MockObject\MockObject $node */
		$node = $this->createMock(Node::class);
		$node->expects($this->once())
			->method('getPath')
			->willReturn($path);

		$this->assertSame($expected, self::invokePrivate($this->helper, 'getMountedPath', [$node]));
	}
}
