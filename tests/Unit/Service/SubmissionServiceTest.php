<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2021 Jonas Rittershofer <jotoeri@users.noreply.github.com>
 *
 * @author Jonas Rittershofer <jotoeri@users.noreply.github.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Forms\Tests\Unit\Service;

use OCA\Forms\Service\SubmissionService;

use OCA\Forms\Db\Answer;
use OCA\Forms\Db\AnswerMapper;
use OCA\Forms\Db\Form;
use OCA\Forms\Db\FormMapper;
use OCA\Forms\Db\Question;
use OCA\Forms\Db\QuestionMapper;
use OCA\Forms\Db\Submission;
use OCA\Forms\Db\SubmissionMapper;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class SubmissionServiceTest extends TestCase {

	/** @var SubmissionService */
	private $submissionService;

	/** @var FormMapper|MockObject */
	private $formMapper;

	/** @var QuestionMapper|MockObject */
	private $questionMapper;

	/** @var SubmissionMapper|MockObject */
	private $submissionMapper;

	/** @var AnswerMapper|MockObject */
	private $answerMapper;

	/** @var IRootFolder|MockObject */
	private $storage;

	/** @var IConfig|MockObject */
	private $config;

	/** @var IDateTimeFormatter|MockObject */
	private $dateTimeFormatter;

	/** @var IL10N|MockObject */
	private $l10n;

	/** @var ILogger|MockObject */
	private $logger;

	/** @var IUserManager|MockObject */
	private $userManager;

	public function setUp(): void {
		parent::setUp();
		$this->formMapper = $this->createMock(FormMapper::class);
		$this->questionMapper = $this->createMock(QuestionMapper::class);
		$this->submissionMapper = $this->createMock(SubmissionMapper::class);
		$this->answerMapper = $this->createMock(AnswerMapper::class);
		$this->storage = $this->createMock(IRootFolder::class);
		$this->config = $this->createMock(IConfig::class);
		$this->dateTimeFormatter = $this->createMock(IDateTimeFormatter::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(ILogger::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->willReturn('currentUser');
		$userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->l10n->expects($this->any())
			->method('t')
			->will($this->returnCallback(function (string $identity) {
				return $identity;
			}));

		$this->submissionService = new SubmissionService(
			$this->formMapper,
			$this->questionMapper,
			$this->submissionMapper,
			$this->answerMapper,
			$this->storage,
			$this->config,
			$this->dateTimeFormatter,
			$this->l10n,
			$this->logger,
			$this->userManager,
			$userSession
		);
	}

	public function dataWriteCsvToCloud() {
		return [
			'rootFolder' => ['', Folder::class, '', 'Some nice Form Title (responses).csv', false],
			'subFolder' => ['/folder path', Folder::class, '', 'Some nice Form Title (responses).csv', false],
			'nonCsv-file' => ['/fileName.txt', File::class, 'txt', 'Some nice Form Title (responses).csv', false],
			'csv-file' => ['/fileName.csv', File::class, 'csv', 'fileName.csv', true],
		];
	}

	/**
	 * @dataProvider dataWriteCsvToCloud
	 *
	 * @param string $path Selected user-path (from frontend)
	 * @param string $pathClass Type of $path - Folder or File
	 * @param string $pathExtension Extension of the given file within path
	 * @param string $expectedFileName
	 * @param bool $fileExists If the file to write into does exist already.
	 */
	public function testWriteCsvToCloud(string $path, string $pathClass, string $pathExtension, string $expectedFileName, bool $fileExists) {
		// Simple default Form Data here, details are tested in testGetSubmissionsCsv
		$dataExpectation = $this->setUpSimpleCsvTest();

		$fileNode = $this->createMock(File::class);
		$fileNode->expects($this->once())
			->method('putContent')
			->with($dataExpectation);

		$folderNode = $this->createMock(Folder::class);
		if ($fileExists) {
			$folderNode->expects($this->once())
				->method('get')
				->with($expectedFileName)
				->willReturn($fileNode);
		} else {
			$folderNode->expects($this->exactly(2))
				->method('get')
				->with($expectedFileName)
				->will($this->onConsecutiveCalls(
					$this->throwException(new NotFoundException('File not found')),
					$fileNode
				));
			$folderNode->expects($this->once())
				->method('newFile')
				->with($expectedFileName);
		}

		if ($pathClass === File::class) {
			$pathNode = $this->createMock(File::class);
			$pathNode->expects($this->once())
				->method('getExtension')
				->willReturn($pathExtension);
			$pathNode->expects($this->any())
				->method('getName')
				->willReturn($expectedFileName);
			$pathNode->expects($this->once())
				->method('getParent')
				->willReturn($folderNode);
		} elseif ($pathClass === Folder::class) {
			$pathNode = $folderNode;
		}

		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())
			->method('get')
			->with($path)
			->willReturn($pathNode);
		$this->storage->expects($this->once())
			->method('getUserFolder')
			->with('currentUser')
			->willReturn($userFolder);

		$this->assertEquals($expectedFileName, $this->submissionService->writeCsvToCloud('abcdefg', $path));
	}

	// Data for SubmissionCsv
	public function dataGetSubmissionsCsv() {
		return [
			'two-basic-submissions' => [
				// Questions
				[
					['id' => 1, 'text' => 'Question 1'],
					['id' => 2, 'text' => 'Question 2']
				],
				// Array of Submissions incl. Answers
				[
					[
						'id' => 1,
						'userId' => 'user1',
						'answers' => [
							['questionId' => 1, 'text' => 'Q1A1'],
							['questionId' => 2, 'text' => 'Q2A1']
						]
					],
					[
						'id' => 2,
						'userId' => 'user2',
						'answers' => [
							['questionId' => 1, 'text' => 'Q1A2'],
							['questionId' => 2, 'text' => 'Q2A2']
						]
					],
				],
				// Expected CSV-Result
				'
				"User ID","User display name","Timestamp","Question 1","Question 2"
				"user1","User 1","01.01.01, 01:01","Q1A1","Q2A1"
				"user2","User 2","01.01.01, 01:01","Q1A2","Q2A2"
				'
			],
			'checkbox-multi-answers' => [
				// Questions
				[
					['id' => 1, 'type' => 'multiple', 'text' => 'Question 1']
				],
				// Array of Submissions incl. Answers
				[
					[
						'id' => 1,
						'userId' => 'user1',
						'answers' => [
							['questionId' => 1, 'text' => 'Q1A1'],
							['questionId' => 1, 'text' => 'Q1A2'],
							['questionId' => 1, 'text' => 'Q1A3'],
						]
					],
				],
				// Expected CSV-Result
				'
				"User ID","User display name","Timestamp","Question 1"
				"user1","User 1","01.01.01, 01:01","Q1A1; Q1A2; Q1A3"
				'
			],
			'anonymous-user' => [
				// Questions
				[
					['id' => 1, 'text' => 'Question 1']
				],
				// Array of Submissions incl. Answers
				[
					[
						'id' => 1,
						'userId' => 'anon-user-xyz',
						'answers' => [
							['questionId' => 1, 'text' => 'Q1A1'],
						]
					],
				],
				// Expected CSV-Result
				'
				"User ID","User display name","Timestamp","Question 1"
				"","Anonymous user","01.01.01, 01:01","Q1A1"
				'
			],
			'deleted-user' => [
				// Questions
				[
					['id' => 1, 'text' => 'Question 1']
				],
				// Array of Submissions incl. Answers
				[
					[
						'id' => 1,
						'userId' => 'deleted_userId',
						'answers' => [
							['questionId' => 1, 'text' => 'Q1A1'],
						]
					],
				],
				// Expected CSV-Result
				'
				"User ID","User display name","Timestamp","Question 1"
				"deleted_userId","","01.01.01, 01:01","Q1A1"
				'
			],
			'questions-not-answered' => [
				// Questions
				[
					['id' => 1, 'text' => 'Question 1'],
					['id' => 2, 'text' => 'Question 2'],
					['id' => 3, 'text' => 'Question 3']
				],
				// Array of Submissions incl. Answers
				[
					[
						'id' => 1,
						'userId' => 'user1',
						'answers' => [
							['questionId' => 2, 'text' => 'Q2A1']
						]
					],
				],
				// Expected CSV-Result
				'
				"User ID","User display name","Timestamp","Question 1","Question 2","Question 3"
				"user1","User 1","01.01.01, 01:01","","Q2A1",""
				'
			],
			/* No submissions, but request via api */
			'no-submission' => [
				// Questions
				[
					['id' => 1, 'text' => 'Question 1']
				],
				// Array of Submissions incl. Answers
				[],
				// Expected CSV-Result
				'
				"User ID","User display name","Timestamp","Question 1"
				'
			],
			/* All Questions e.g. got deleted */
			'no-questions' => [
				// Questions
				[],
				// Array of Submissions incl. Answers
				[
					[
						'id' => 1,
						'userId' => 'anon-user-xyz',
						'answers' => [
							['questionId' => 1, 'text' => 'Q1A1'],
						]
					],
				],
				// Expected CSV-Result
				'
				"User ID","User display name","Timestamp"
				"","Anonymous user","01.01.01, 01:01"
				'
			],
		];
	}
	/**
	 * @dataProvider dataGetSubmissionsCsv
	 *
	 * @param array $questions
	 * @param array $submissions
	 * @param string $csvText
	 */
	public function testGetSubmissionsCsv(array $questions, array $submissions, string $csvText) {
		$dataExpectation = $this->setUpCsvTest($questions, $submissions, $csvText);

		$this->assertEquals([
			'fileName' => 'Some nice Form Title (responses).csv',
			'data' => $dataExpectation,
		], $this->submissionService->getSubmissionsCsv('abcdefg'));
	}

	/**
	 * Setting up a very simple default CsvTest
	 */
	private function setUpSimpleCsvTest(): string {
		return $this->setUpCsvTest(
			[
				// Single Question
				['id' => 1, 'text' => 'Question 1']
			],
			[
				//Single Submission
				[
					'id' => 1,
					'userId' => 'user1',
					'answers' => [
						['questionId' => 1, 'text' => 'Q1A1']
					]
				]
			],
			// Expected CSV-Result
			'
			"User ID","User display name","Timestamp","Question 1"
			"user1","User 1","01.01.01, 01:01","Q1A1"
			'
		);
	}

	/**
	 * Setting up all the mock-data for a full Form incl. Submissions
	 */
	private function setUpCsvTest(array $questions, array $submissions, string $csvText): string {
		$form = new Form();
		$form->setId(5);
		$form->setHash('abcdefg');
		$form->setTitle('Some nice Form Title');
		$this->formMapper->expects($this->any())
			->method('findByHash')
			->with('abcdefg')
			->willReturn($form);

		$this->submissionMapper->expects($this->once())
			->method('findByForm')
			->with(5)
			// Return SubmissionObjects for given Submissions
			->will($this->returnCallback(function (int $formId) use ($submissions) {
				$submissionEntities = array_map(function ($submission) {
					unset($submission['answers']);
					return Submission::fromParams($submission);
				}, $submissions);

				return $submissionEntities;
			}));

		$this->questionMapper->expects($this->once())
		->method('findByForm')
		->with(5)
		// Return QuestionObjects for given Questions
		->will($this->returnCallback(function (int $formId) use ($questions) {
			$questionEntities = array_map(function ($question) {
				return Question::fromParams($question);
			}, $questions);

			return $questionEntities;
		}));

		date_default_timezone_set('Europe/Berlin');
		$this->config->expects($this->once())
			->method('getUserValue')
			->with('core', 'timezone', 'currentUser', 'Europe/Berlin')
			->willReturn('Europe/Berlin');

		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->will($this->onConsecutiveCalls('user1', 'user2'));
		$user->expects($this->any())
			->method('getDisplayName')
			->will($this->onConsecutiveCalls('User 1', 'User 2'));
		$this->userManager->expects($this->any())
			->method('get')
			->will($this->returnValueMap([
				['user1', $user],
				['user2', $user],
				['unknown', null]
			]));

		// Just using any timestamp here
		$this->dateTimeFormatter->expects($this->any())
			->method('formatDateTime')
			->willReturn('01.01.01, 01:01');

		$this->answerMapper->expects($this->any())
		->method('findBySubmission')
		// Return AnswerObjects for corresponding submission
		->will($this->returnCallback(function (int $submissionId) use ($submissions) {
			$matchingSubmission = array_filter($submissions, function ($submission) use ($submissionId) {
				return $submission['id'] === $submissionId;
			});

			$answerEntities = array_map(function ($answer) {
				return Answer::fromParams($answer);
			}, current($matchingSubmission)['answers']);

			return $answerEntities;
		}));

		// Prepend BOM-Sequence as Writer does and remove formatting-artefacts of dataProvider.
		$dataExpectation = chr(239).chr(187).chr(191) . ltrim(preg_replace('/\t+/', '', $csvText));

		return $dataExpectation;
	}

	// Data for validation of Submissions
	public function dataValidateSubmission() {
		return [
			'required-not-answered' => [
				// Questions
				[
					['id' => 1, 'type' => 'short', 'isRequired' => true]
				],
				// Answers
				[],
				// Expected Result
				false
			],
			'required-not-answered-string' => [
				// Questions
				[
					['id' => 1, 'type' => 'short', 'isRequired' => true]
				],
				// Answers
				[
					'1' => ['']
				],
				// Expected Result
				false
			],
			'more-than-allowed' => [
				// Questions
				[
					['id' => 1, 'type' => 'multiple_unique', 'isRequired' => false, 'options' => [
						['id' => 3],
						['id' => 5]
					]]
				],
				// Answers
				[
					'1' => [3,5]
				],
				// Expected Result
				false
			],
			'option-not-known' => [
				// Questions
				[
					['id' => 1, 'type' => 'multiple', 'isRequired' => false, 'options' => [
						['id' => 3],
						['id' => 5]
					]],
				],
				// Answers
				[
					'1' => [3,10]
				],
				// Expected Result
				false
			],
			'question-not-known' => [
				// Questions
				[
					['id' => 1, 'type' => 'short', 'isRequired' => false]
				],
				// Answers
				[
					'2' => ['answer']
				],
				// Expected Result
				false
			],
			'invalid-date-question' => [
				// Questions
				[
					['id' => 1, 'type' => 'date', 'isRequired' => false]
				],
				// Answers
				[
					'1' => ['31.12.2022']
				],
				// Expected Result
				false
			],
			'full-good-submission' => [
				// Questions
				[
					['id' => 1, 'type' => 'short', 'isRequired' => false],
					['id' => 2, 'type' => 'long', 'isRequired' => true],
					['id' => 3, 'type' => 'date', 'isRequired' => true],
					['id' => 4, 'type' => 'datetime', 'isRequired' => false],
					['id' => 5, 'type' => 'multiple', 'isRequired' => false, 'options' => [
						['id' => 1],
						['id' => 2]
					]],
					['id' => 6, 'type' => 'multiple_unique', 'isRequired' => false, 'options' => [
						['id' => 3],
						['id' => 4]
					]],
					['id' => 7, 'type' => 'dropdown', 'isRequired' => true, 'options' => [
						['id' => 5],
						['id' => 6]
					]],
					['id' => 8, 'type' => 'time', 'isRequired' => false],
				],
				// Answers
				[
					'1' => ['answer'],
					'2' => ['answerABitLonger'],
					'3' => ['2021-04-28'],
					'4' => ['2021-04-30 04:40'],
					'5' => [1,2],
					'6' => [4],
					'7' => [5],
					'8' => ['17:45']
				],
				// Expected Result
				true
			]
		];
	}

	/**
	 * @dataProvider dataValidateSubmission
	 *
	 * @param array $questions
	 * @param array $answers
	 * @param bool $expected
	 */
	public function testValidateSubmission(array $questions, array $answers, bool $expected) {
		$this->assertEquals($expected, $this->submissionService->validateSubmission($questions, $answers));
	}
};
