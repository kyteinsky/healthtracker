<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page page--narrow page--roomy">
		<header class="page__header page__header--center">
			<h2 class="page__title">
				{{ t('healthtracker', 'Documents') }}
			</h2>
			<div class="page__tools">
				<NcButton variant="primary" :disabled="picking" @click="openPicker">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('healthtracker', 'Add files') }}
				</NcButton>
			</div>
		</header>

		<div
			v-if="!store.providers.ocr || !store.providers.llm"
			class="provider-warn">
			<span class="provider-warn__icon" aria-hidden="true">⚠</span>
			<span>
				{{ t('healthtracker', 'OCR and Chat task providers are not both available. Ask your Nextcloud admin to enable the OCR and Chat (text-to-text) providers in the AI admin settings so lab reports can be processed.') }}
			</span>
		</div>

		<section class="documents">
			<SkeletonList v-if="store.loading && !store.documents.length" :rows="4" />
			<template v-else-if="store.documents.length">
				<div class="table-wrap">
					<table class="tbl">
						<thead>
							<tr>
								<th>{{ t('healthtracker', 'File') }}</th>
								<th>{{ t('healthtracker', 'Status') }}</th>
								<th>{{ t('healthtracker', 'Added') }}</th>
								<th>{{ t('healthtracker', 'Actions') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="doc in store.documents" :key="doc.id">
								<td>{{ doc.fileName || doc.filePath }}</td>
								<td><DocumentStatusBadge :status="doc.status" /></td>
								<td>{{ formatDate(doc.createdAt) }}</td>
								<td class="documents__actions">
									<NcButton
										v-if="canReprocess(doc.status)"
										variant="secondary"
										size="small"
										:disabled="processingId === doc.id"
										@click="reprocess(doc)">
										{{ t('healthtracker', 'Re-process') }}
									</NcButton>
									<NcButton
										v-if="doc.status === 'DONE' || doc.status === 'OCR_DONE' || doc.status === 'OCR_FAILED' || doc.status === 'FAILED'"
										variant="tertiary"
										size="small"
										@click="viewOcr(doc)">
										{{ t('healthtracker', 'View OCR text') }}
									</NcButton>
									<NcButton
										variant="tertiary"
										size="small"
										@click="remove(doc)">
										{{ t('healthtracker', 'Delete') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>
			<NcEmptyContent
				v-else
				:name="t('healthtracker', 'No documents yet')"
				:description="t('healthtracker', 'Add lab report files (images or PDFs) to have them read and parsed automatically.')">
				<template #icon>
					<FileDocumentOutline :size="40" />
				</template>
				<template #action>
					<NcButton variant="primary" @click="openPicker">
						{{ t('healthtracker', 'Add files') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</section>

		<NcDialog
			v-if="ocrDialog.doc"
			:name="t('healthtracker', 'OCR text for {file}', { file: ocrDialog.doc.fileName })"
			:open="true"
			@update:open="(v) => { if (!v) ocrDialog.doc = null }">
			<pre class="ocr-text">{{ ocrDialog.text || t('healthtracker', 'No OCR text available.') }}</pre>
		</NcDialog>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import api from '../api.js'
import DocumentStatusBadge from '../components/DocumentStatusBadge.vue'
import SkeletonList from '../components/SkeletonList.vue'
import { store } from '../store.js'

const PROCESSING_STATUSES = ['PENDING', 'OCR_RUNNING', 'OCR_DONE', 'EXTRACTION_RUNNING']
const POLL_INTERVAL = 5000

export default {
	name: 'Documents',
	components: { NcButton, NcDialog, NcEmptyContent, DocumentStatusBadge, SkeletonList, Plus, FileDocumentOutline },

	setup() {
		return { store }
	},

	data() {
		return {
			picking: false,
			processingId: null,
			pollTimer: null,
			ocrDialog: { doc: null, text: '' },
		}
	},

	mounted() {
		store.refreshDocuments()
		this.startPolling()
	},

	beforeUnmount() {
		this.stopPolling()
	},

	methods: {
		t,

		async openPicker() {
			this.picking = true
			try {
				const picker = getFilePickerBuilder(t('healthtracker', 'Add files'))
					.setMultiSelect(true)
					.allowDirectories(true)
					.addMimeTypeFilter('image/png')
					.addMimeTypeFilter('image/jpeg')
					.addMimeTypeFilter('application/pdf')
					.startAt('/')
					.build()
				const nodes = await picker.pickNodes()
				const fileIds = (nodes || [])
					.filter((n) => n && n.fileid)
					.map((n) => n.fileid)
				if (!fileIds.length) {
					return
				}
				await api.createDocuments(fileIds)
				showSuccess(t('healthtracker', 'Files added — processing started.'))
				await store.refreshDocuments()
			} catch (e) {
				if (e && e.name === 'UserCancelledError') {
					return
				}
				console.error('HealthTracker: file pick failed', e)
				showError(t('healthtracker', 'Could not add files'))
			} finally {
				this.picking = false
			}
		},

		canReprocess(status) {
			return status === 'FAILED' || status === 'OCR_FAILED' || status === 'PENDING'
		},

		async reprocess(doc) {
			this.processingId = doc.id
			try {
				await api.processDocument(doc.id)
				showSuccess(t('healthtracker', 'Re-processing started.'))
				await store.refreshDocuments()
			} catch (e) {
				showError(t('healthtracker', 'Could not re-process document'))
			} finally {
				this.processingId = null
			}
		},

		async viewOcr(doc) {
			try {
				const res = await api.getOcrText(doc.id)
				this.ocrDialog = { doc, text: res.ocrText || '' }
			} catch (e) {
				showError(t('healthtracker', 'Could not load OCR text'))
			}
		},

		async remove(doc) {
			try {
				await api.deleteDocument(doc.id)
				showSuccess(t('healthtracker', 'Document deleted'))
				await store.refreshDocuments()
			} catch (e) {
				showError(t('healthtracker', 'Could not delete document'))
			}
		},

		isProcessing() {
			return store.documents.some((d) => PROCESSING_STATUSES.includes(d.status))
		},

		startPolling() {
			this.stopPolling()
			this.pollTimer = setInterval(async () => {
				if (!this.isProcessing()) {
					return
				}
				await store.refreshDocuments()
			}, POLL_INTERVAL)
		},

		stopPolling() {
			if (this.pollTimer) {
				clearInterval(this.pollTimer)
				this.pollTimer = null
			}
		},

		formatDate(d) {
			if (!d) return ''
			return String(d).replace('T', ' ').slice(0, 16)
		},
	},
}
</script>

<style scoped lang="scss">
.provider-warn {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 12px 14px;
	border-radius: var(--border-radius-large, 12px);
	background: color-mix(in srgb, var(--color-warning) 14%, var(--color-main-background));
	border: 1px solid color-mix(in srgb, var(--color-warning) 35%, transparent);
	color: var(--color-main-text);
	font-size: 0.9rem;

	&__icon {
		flex: 0 0 auto;
		font-size: 1.1rem;
		line-height: 1.3;
	}
}

.documents {
	&__actions {
		display: flex;
		gap: 6px;
		flex-wrap: wrap;
	}
}

.ocr-text {
	white-space: pre-wrap;
	word-break: break-word;
	max-height: 60vh;
	overflow: auto;
	margin: 0;
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large, 12px);
	font-family: var(--font-family-mono, monospace);
	font-size: 0.85rem;
}
</style>
