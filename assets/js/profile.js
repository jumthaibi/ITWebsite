(() => {
    const modal = document.getElementById('profileModal');
    if (!modal) return;

    const displayAvatar = document.getElementById('profileDisplayAvatar');
    const displayName = document.getElementById('profileDialogTitle');
    const displayRole = document.getElementById('profileDisplayRole');
    const displayBio = document.getElementById('profileDisplayBio');
    const editToggle = document.getElementById('profileEditToggle');
    const editForm = document.getElementById('profileEditForm');
    const editUsername = document.getElementById('profileEditUsername');
    const editBio = document.getElementById('profileEditBio');
    const editImage = document.getElementById('profileEditImage');
    const removeImage = document.getElementById('profileRemoveImage');
    const editCancel = document.getElementById('profileEditCancel');
    const formMessage = document.getElementById('profileFormMessage');
    const closeButton = document.getElementById('closeProfile');
    const config = window.profileConfig || {};
    const endpoint = config.endpoint || 'profile.php';
    let activeProfile = null;

    // Server accepts up to 5 MB; aim a little under that so the compressed
    // file clears the check with room to spare.
    const MAX_IMAGE_BYTES = 768 * 1024;
    const MAX_IMAGE_DIMENSION = 800;

    // Shrinks/compresses an oversized image in the browser (quality first,
    // then dimensions) so people don't hit the 5 MB upload limit just
    // because their phone photo is large. Quality loss is expected and
    // acceptable here — a smaller avatar beats a rejected upload.
    const compressImageFile = (file, {
        maxBytes = MAX_IMAGE_BYTES,
        maxDimension = MAX_IMAGE_DIMENSION,
        mimeType = 'image/jpeg',
        startQuality = 0.9,
        minQuality = 0.4,
    } = {}) => new Promise((resolve, reject) => {
        if (file.size <= maxBytes) {
            resolve(file);
            return;
        }
        if (file.type === 'image/gif') {
            reject(new Error('That GIF is over 5 MB and can\'t be auto-compressed without losing the animation — please pick a smaller one.'));
            return;
        }
        if (!file.type.startsWith('image/')) {
            reject(new Error('Please choose an image file.'));
            return;
        }

        const objectUrl = URL.createObjectURL(file);
        const img = new Image();

        img.onload = () => {
            let { width, height } = img;
            if (width > maxDimension || height > maxDimension) {
                const scale = maxDimension / Math.max(width, height);
                width = Math.round(width * scale);
                height = Math.round(height * scale);
            }

            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');

            const renderAt = (w, h) => {
                canvas.width = w;
                canvas.height = h;
                ctx.clearRect(0, 0, w, h);
                ctx.drawImage(img, 0, 0, w, h);
            };

            const tryShrink = (quality, dimW, dimH, dimensionAttempts) => {
                renderAt(dimW, dimH);
                canvas.toBlob((blob) => {
                    if (!blob) {
                        URL.revokeObjectURL(objectUrl);
                        reject(new Error('That image could not be compressed. Please try a different one.'));
                        return;
                    }
                    if (blob.size <= maxBytes || (quality <= minQuality && dimensionAttempts >= 4)) {
                        URL.revokeObjectURL(objectUrl);
                        const newName = file.name.replace(/\.[^./\\]+$/, '') + '.jpg';
                        resolve(new File([blob], newName, { type: mimeType }));
                        return;
                    }
                    if (quality > minQuality) {
                        tryShrink(Math.max(quality - 0.1, minQuality), dimW, dimH, dimensionAttempts);
                    } else {
                        tryShrink(startQuality, Math.round(dimW * 0.8), Math.round(dimH * 0.8), dimensionAttempts + 1);
                    }
                }, mimeType, quality);
            };

            tryShrink(startQuality, width, height, 0);
        };
        img.onerror = () => {
            URL.revokeObjectURL(objectUrl);
            reject(new Error('That image could not be read. Please try a different file.'));
        };
        img.src = objectUrl;
    });

    const setAvatar = (element, imagePath, fallback = '🌿') => {
        if (!element) return;
        element.replaceChildren();
        if (imagePath) {
            const image = document.createElement('img');
            const src = String(imagePath);
            image.src = /^(https?:\/\/|data:)/i.test(src)
                ? src
                : `../${src.replace(/^\/+/, '')}`;
            image.alt = '';
            image.className = 'profile-avatar-image';
            element.appendChild(image);
        } else {
            element.textContent = fallback;
        }
    };

    const setMessage = (message = '', isError = true) => {
        if (!formMessage) return;
        formMessage.textContent = message;
        formMessage.hidden = !message;
        formMessage.classList.toggle('is-error', isError);
        formMessage.classList.toggle('is-success', !isError);
    };

    const closeModal = () => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        if (editForm) editForm.hidden = true;
        if (editToggle) editToggle.hidden = true;
        setMessage('');
    };

    const renderProfile = (profile) => {
        activeProfile = profile;
        displayName.textContent = profile.username || 'Student profile';
        displayRole.textContent = profile.is_admin ? 'Portal administrator' : 'IT Students Hub member';
        displayRole.hidden = false;
        displayBio.textContent = profile.bio || 'No bio added yet.';
        setAvatar(displayAvatar, profile.profile_image);

        const currentUserId = Number(config.currentUserId || 0);
        const isOwnProfile = currentUserId > 0 && currentUserId === Number(profile.id);
        if (editToggle) editToggle.hidden = !isOwnProfile;
        if (editForm) editForm.hidden = true;
        if (isOwnProfile) {
            editUsername.value = profile.username || '';
            editBio.value = profile.bio || '';
            editImage.value = '';
            if (removeImage) removeImage.checked = false;
        }
    };

    const openProfile = async (userId) => {
        if (!userId) return;
        setMessage('');
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        displayName.textContent = 'Loading profile…';
        displayRole.textContent = '';
        displayBio.textContent = '';
        setAvatar(displayAvatar, '');

        try {
            const response = await fetch(`${endpoint}?profile_api=1&user_id=${encodeURIComponent(userId)}`, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'That profile could not be loaded.');
            renderProfile(data.profile);
        } catch (error) {
            displayName.textContent = 'Profile unavailable';
            displayRole.textContent = '';
            displayBio.textContent = error.message || 'That profile could not be loaded.';
            setAvatar(displayAvatar, '');
            if (editToggle) editToggle.hidden = true;
        }
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-profile-user-id]');
        if (trigger) {
            event.preventDefault();
            openProfile(Number(trigger.dataset.profileUserId));
        }
    });

    editToggle?.addEventListener('click', () => {
        editForm.hidden = false;
        editToggle.hidden = true;
        editUsername.focus();
    });

    editCancel?.addEventListener('click', () => {
        if (activeProfile) renderProfile(activeProfile);
    });

    // Added early validation listener on file input change to compress immediately and show a notice
    editImage?.addEventListener('change', async (event) => {
        const file = event.target.files?.[0];
        if (!file) return;
        if (removeImage) removeImage.checked = false;

        if (file.size > MAX_IMAGE_BYTES) {
            try {
                setMessage('Compressing image automatically…', false);
                const compressed = await compressImageFile(file);
                const dt = new DataTransfer();
                dt.items.add(compressed);
                editImage.files = dt.files;
                setMessage(`Compressed successfully to ${(compressed.size / (1024 * 1024)).toFixed(1)} MB!`, false);
                window.setTimeout(() => setMessage(''), 2500);
            } catch (error) {
                setMessage(error.message || 'That image could not be compressed.', true);
                editImage.value = '';
            }
        }
    });

    removeImage?.addEventListener('change', () => {
        if (removeImage.checked && editImage) editImage.value = '';
    });

    editForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const rawImage = editImage?.files?.[0] || null;
        let preparedImage = null;
        if (rawImage) {
            try {
                if (rawImage.size > MAX_IMAGE_BYTES) {
                    setMessage('Compressing image…', false);
                }
                preparedImage = await compressImageFile(rawImage);
            } catch (error) {
                setMessage(error.message || 'That image could not be processed.', true);
                return;
            }
        }

        setMessage('Saving profile…', false);
        const formData = new FormData(editForm);
        if (preparedImage) {
            formData.set('profile_image', preparedImage, preparedImage.name);
        }
        formData.append('profile_action', 'update');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Your profile could not be saved.');
            renderProfile(data.profile);
            editForm.hidden = false;
            editToggle.hidden = true;
            setMessage('Profile saved.', false);
            window.setTimeout(() => setMessage(''), 1800);
        } catch (error) {
            setMessage(error.message || 'Your profile could not be saved.', true);
        }
    });

    closeButton?.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });
})();