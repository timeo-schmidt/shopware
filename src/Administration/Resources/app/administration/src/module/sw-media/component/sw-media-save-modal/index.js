import template from './sw-media-save-modal.html.twig';
import './sw-media-save-modal.scss';

const { Context } = Shopware;
/**
 * @event media-modal-selection-change EntityProxy[]
 * @event closeModal (void)
 * @sw-package discovery
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    inject: [
        'repositoryFactory',
    ],

    emits: [
        'save-media',
        'modal-close',
    ],

    props: {
        initialFolderId: {
            type: String,
            required: false,
            default: null,
        },

        initialFileName: {
            type: String,
            required: false,
            default: null,
        },

        fileType: {
            type: String,
            required: false,
            default: 'png',
        },
    },

    data() {
        return {
            fileName: this.initialFileName || null,
            folderId: this.initialFolderId || null,
            currentFolder: null,
            compact: false,
            selection: [],
        };
    },

    computed: {
        mediaFolderRepository() {
            return this.repositoryFactory.create('media_folder');
        },
    },

    watch: {
        folderId() {
            this.fetchCurrentFolder();
        },
    },

    created() {
        this.createdComponent();
    },

    mounted() {
        this.mountedComponent();
    },

    beforeUnmount() {
        this.beforeDestroyComponent();
    },

    methods: {
        createdComponent() {
            this.fetchCurrentFolder();
            this.addResizeListener();
        },

        mountedComponent() {
            this.getComponentWidth();
        },

        beforeDestroyComponent() {
            this.removeOnResizeListener();
        },

        addResizeListener() {
            window.addEventListener('resize', this.getComponentWidth);
        },

        removeOnResizeListener() {
            window.removeEventListener('resize', this.getComponentWidth);
        },

        getComponentWidth() {
            const componentWidth = this.$el.getBoundingClientRect().width;
            this.compact = componentWidth <= 900;
        },

        async fetchCurrentFolder() {
            if (!this.folderId) {
                this.currentFolder = null;
                return;
            }

            this.currentFolder = await this.mediaFolderRepository.get(this.folderId, Context.api);
        },

        onSaveMedia() {
            this.$emit('save-media', {
                fileName: this.fileName,
                folderId: this.folderId,
            });

            this.onEmitModalClosed();
        },

        onEmitModalClosed() {
            this.$emit('modal-close');
        },
    },
};
