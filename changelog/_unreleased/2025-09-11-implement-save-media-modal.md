---
title: Implement save media modal
author: Quynh Nguyen
author_email: q.nguyen@shopware.com
author_github: @quynhnguyen68
---
# Administration
* Added component `sw-media-save-modal` extended from `sw-media-modal-v2`
* Changed in `src/app/component/structure/sw-media-modal-renderer/index.ts`
    * Added method `onSaveMedia`
    * Added method `closeSaveModal` 
    * Added method `saveMediaModal` 
* Added props `allowCreateFolder` in `src/module/sw-media/component/sw-media-library/index.js`.
