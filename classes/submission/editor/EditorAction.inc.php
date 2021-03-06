<?php

/**
 * @file classes/submission/editor/EditorAction.inc.php
 *
 * Copyright (c) 2003-2010 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class EditorAction
 * @ingroup submission
 *
 * @brief EditorAction class.
 */

// $Id$


import('classes.submission.sectionEditor.SectionEditorAction');

class EditorAction extends SectionEditorAction {
	/**
	 * Actions.
	 */

	/**
	 * Assigns a section editor to a submission.
	 * @param $articleId int
	 * @return boolean true iff ready for redirect
	 */
	function assignEditor($articleId, $sectionEditorId, $isEditor = false, $send = false) {
		$editorSubmissionDao =& DAORegistry::getDAO('EditorSubmissionDAO');
		$editAssignmentDao =& DAORegistry::getDAO('EditAssignmentDAO');
		$userDao =& DAORegistry::getDAO('UserDAO');

		$user =& Request::getUser();
		$journal =& Request::getJournal();

		$editorSubmission =& $editorSubmissionDao->getEditorSubmission($articleId);
		$sectionEditor =& $userDao->getUser($sectionEditorId);
		if (!isset($sectionEditor)) return true;

		import('classes.mail.ArticleMailTemplate');
		$email = new ArticleMailTemplate($editorSubmission, 'EDITOR_ASSIGN');

		if ($user->getId() === $sectionEditorId || !$email->isEnabled() || ($send && !$email->hasErrors())) {
			HookRegistry::call('EditorAction::assignEditor', array(&$editorSubmission, &$sectionEditor, &$isEditor, &$email));
			if ($email->isEnabled() && $user->getId() !== $sectionEditorId) {
				$email->setAssoc(ARTICLE_EMAIL_EDITOR_ASSIGN, ARTICLE_EMAIL_TYPE_EDITOR, $sectionEditor->getId());
				$email->send();
			}

			$editAssignment = new EditAssignment();
			$editAssignment->setArticleId($articleId);
			$editAssignment->setCanEdit(1);
			$editAssignment->setCanReview(1);

			// Make the selected editor the new editor
			$editAssignment->setEditorId($sectionEditorId);
			$editAssignment->setDateNotified(Core::getCurrentDate());
			$editAssignment->setDateUnderway(null);

			$editAssignments =& $editorSubmission->getEditAssignments();
			array_push($editAssignments, $editAssignment);
			$editorSubmission->setEditAssignments($editAssignments);

			$editorSubmissionDao->updateEditorSubmission($editorSubmission);

			// Add log
			import('classes.article.log.ArticleLog');
			import('classes.article.log.ArticleEventLogEntry');
			ArticleLog::logEvent($articleId, ARTICLE_LOG_EDITOR_ASSIGN, ARTICLE_LOG_TYPE_EDITOR, $sectionEditorId, 'log.editor.editorAssigned', array('editorName' => $sectionEditor->getFullName(), 'articleId' => $articleId));
			return true;
		} else {
			if (!Request::getUserVar('continued')) {
				$email->addRecipient($sectionEditor->getEmail(), $sectionEditor->getFullName());
				$paramArray = array(
					'editorialContactName' => $sectionEditor->getFullName(),
					'editorUsername' => $sectionEditor->getUsername(),
					'editorPassword' => $sectionEditor->getPassword(),
					'editorialContactSignature' => $user->getContactSignature(),
					'submissionUrl' => Request::url(null, $isEditor?'editor':'sectionEditor', 'submissionReview', $articleId),
					'submissionEditingUrl' => Request::url(null, $isEditor?'editor':'sectionEditor', 'submissionReview', $articleId)
				);
				$email->assignParams($paramArray);
			}
			$email->displayEditForm(Request::url(null, null, 'assignEditor', 'send'), array('articleId' => $articleId, 'editorId' => $sectionEditorId));
			return false;
		}
	}

	/**
	 * Rush a new submission into the end of the editing queue.
	 * @param $article object
	 */
	function expediteSubmission($article) {
		$user =& Request::getUser();

		import('classes.submission.editor.EditorAction');
		import('classes.submission.sectionEditor.SectionEditorAction');
		import('classes.submission.proofreader.ProofreaderAction');

		$sectionEditorSubmissionDao =& DAORegistry::getDAO('SectionEditorSubmissionDAO');
		$sectionEditorSubmission =& $sectionEditorSubmissionDao->getSectionEditorSubmission($article->getId());

		$submissionFile = $sectionEditorSubmission->getSubmissionFile();

		// Add a long entry before doing anything.
		import('classes.article.log.ArticleLog');
		import('classes.article.log.ArticleEventLogEntry');
		ArticleLog::logEvent($article->getId(), ARTICLE_LOG_EDITOR_EXPEDITE, ARTICLE_LOG_TYPE_EDITOR, $user->getId(), 'log.editor.submissionExpedited', array('editorName' => $user->getFullName(), 'articleId' => $article->getId()));

		// 1. Ensure that an editor is assigned.
		$editAssignments =& $sectionEditorSubmission->getEditAssignments();
		if (empty($editAssignments)) {
			// No editors are currently assigned; assign self.
			EditorAction::assignEditor($article->getId(), $user->getId(), true);
		}

		// 2. Accept the submission and send to copyediting.
		$sectionEditorSubmission =& $sectionEditorSubmissionDao->getSectionEditorSubmission($article->getId());
		if (!$sectionEditorSubmission->getFileBySignoffType('SIGNOFF_COPYEDITING_INITIAL', true)) {
			SectionEditorAction::recordDecision($sectionEditorSubmission, SUBMISSION_EDITOR_DECISION_ACCEPT);
			$reviewFile = $sectionEditorSubmission->getReviewFile();
			SectionEditorAction::setCopyeditFile($sectionEditorSubmission, $reviewFile->getFileId(), $reviewFile->getRevision());
		}

		// 3. Add a galley.
		$sectionEditorSubmission =& $sectionEditorSubmissionDao->getSectionEditorSubmission($article->getId());
		$galleys =& $sectionEditorSubmission->getGalleys();
		if (empty($galleys)) {
			// No galley present -- use copyediting file.
			import('classes.file.ArticleFileManager');
			$copyeditFile =& $sectionEditorSubmission->getFileBySignoffType('SIGNOFF_COPYEDITING_INITIAL');
			$fileType = $copyeditFile->getFileType();
			$articleFileManager = new ArticleFileManager($article->getId());
			$fileId = $articleFileManager->copyPublicFile($copyeditFile->getFilePath(), $fileType);

			if (strstr($fileType, 'html')) {
				$galley = new ArticleHTMLGalley();
			} else {
				$galley = new ArticleGalley();
			}
			$galley->setArticleId($article->getId());
			$galley->setFileId($fileId);
			$galley->setLocale(Locale::getLocale());

			if ($galley->isHTMLGalley()) {
				$galley->setLabel('HTML');
			} else {
				if (strstr($fileType, 'pdf')) {
					$galley->setLabel('PDF');
				} else if (strstr($fileType, 'postscript')) {
					$galley->setLabel('Postscript');
				} else if (strstr($fileType, 'xml')) {
					$galley->setLabel('XML');
				} else {
					$galley->setLabel(Locale::translate('common.untitled'));
				}
			}

			$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
			$galleyDao->insertGalley($galley);
		}

		$sectionEditorSubmission->setStatus(STATUS_QUEUED);
		$sectionEditorSubmissionDao->updateSectionEditorSubmission($sectionEditorSubmission);
	}
}

?>
