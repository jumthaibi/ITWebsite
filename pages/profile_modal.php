<div class="profile-modal" id="profileModal" aria-hidden="true">
    <div class="os-window profile-dialog-window" role="dialog" aria-modal="true" aria-labelledby="profileDialogTitle">
        <div class="window-header">
            <div class="window-title"><span class="os-icon">👤</span> Student Profile</div>
            <div class="window-controls">
                <span class="control-btn minimize"></span>
                <span class="control-btn maximize"></span>
                <button class="control-btn auth-window-close" id="closeProfile" type="button" aria-label="Close profile" style="background-color:#e53935"></button>
            </div>
        </div>
        <div class="browser-toolbar">
            <div class="address-bar"><span>Community Profile</span></div>
        </div>
        <div class="window-body profile-dialog-body">
            <div class="content-box profile-panel">
                <div class="profile-panel-heading">
                    <div class="profile-display-avatar" id="profileDisplayAvatar" aria-hidden="true">🌿</div>
                    <div>
                        <span class="card-header-tag">COMMUNITY MEMBER</span>
                        <h2 id="profileDialogTitle">Student profile</h2>
                        <span class="profile-role" id="profileDisplayRole"></span>
                    </div>
                </div>
                <p class="profile-bio" id="profileDisplayBio">No bio added yet.</p>
                <button class="aero-button secondary profile-edit-toggle" id="profileEditToggle" type="button" hidden>Edit my profile</button>
                <form class="profile-edit-form" id="profileEditForm" hidden enctype="multipart/form-data">
                    <label for="profileEditUsername">Username</label>
                    <input id="profileEditUsername" name="username" type="text" minlength="3" maxlength="30" pattern="[A-Za-z0-9_.-]{3,30}" required>
                    <label for="profileEditBio">Bio</label>
                    <textarea id="profileEditBio" name="bio" maxlength="600" placeholder="Tell the community a little about you..."></textarea>
                    <label for="profileEditImage">Profile image</label>
                    <input id="profileEditImage" name="profile_image" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
                    <small>JPG, PNG, GIF, or WebP. Larger images are compressed automatically to fit under 1 MB.</small>
                    <label class="profile-default-image-option" for="profileRemoveImage">
                        <input id="profileRemoveImage" name="remove_profile_image" type="checkbox" value="1">
                        (remove the current photo)🌿 
                    </label>
                    <div class="profile-edit-actions">
                        <button class="aero-button primary" type="submit">Save profile</button>
                        <button class="aero-button secondary" id="profileEditCancel" type="button">Cancel</button>
                    </div>
                    <p class="profile-form-message" id="profileFormMessage" role="alert" hidden></p>
                </form>
            </div>
        </div>
        <div class="window-statusbar"><span>Frutiger Aero Profile System</span><span>Ready</span></div>
    </div>
</div>
