<?php
require_once '../session.php';
include '../db.php';
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPA Calculator | IT Students Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Varela+Round&display=swap" rel="stylesheet">
    <script>
        function addCourseRow() {
            const container = document.getElementById('coursesContainer');
            const rowCount = container.children.length + 1;
            
            const row = document.createElement('div');
            row.className = 'course-row';
            row.style.cssText = "display: flex; align-items: center; gap: 8px; flex-shrink: 0;";
            row.innerHTML = `
                <span class="course-number" style="font-size: 0.8rem; font-weight: bold; color: #0277bd; width: 18px;">${rowCount}.</span>
                <input type="number" value="3" min="1" max="10" placeholder="Hours" style="width: 75px; padding: 6px 8px; border: 1px solid #b3d7ff; border-radius: 6px; font-family: 'Varela Round', sans-serif; font-size: 0.8rem; background: rgba(255,255,255,0.8);">
                <select class="grade-select" style="flex: 1; padding: 6px 8px; border: 1px solid #b3d7ff; border-radius: 6px; font-family: 'Varela Round', sans-serif; font-size: 0.8rem; background: rgba(255,255,255,0.8);">
                    <option value="" disabled selected>Select Grade</option>
                    <option value="4.00">A (4.00)</option>
                    <option value="3.75">-A (3.75)</option>
                    <option value="3.50">+B (3.50)</option>
                    <option value="3.00">B (3.00)</option>
                    <option value="2.75">-B (2.75)</option>
                    <option value="2.50">+C (2.50)</option>
                    <option value="2.00">C (2.00)</option>
                    <option value="1.75">-C (1.75)</option>
                    <option value="1.50">+D (1.50)</option>
                    <option value="1.00">D (1.00)</option>
                    <option value="0.00">-D / F (0.00)</option>
                </select>
                <button type="button" onclick="removeCourseRow(this)" class="aero-button secondary" style="padding: 6px 8px; font-size: 0.75rem; border-radius: 6px; cursor: pointer; width: auto; flex-grow: 0;">❌</button>
            `;
            container.appendChild(row);
            container.scrollTop = container.scrollHeight;
        }

        function removeCourseRow(btn) {
            const row = btn.parentElement;
            row.remove();
            updateRowNumbers();
        }

        function updateRowNumbers() {
            const container = document.getElementById('coursesContainer');
            const rows = container.children;
            for (let i = 0; i < rows.length; i++) {
                const span = rows[i].querySelector('.course-number');
                if (span) {
                    span.textContent = (i + 1) + '.';
                }
            }
        }

        function showAeroAlert(message) {
            const existing = document.getElementById('aeroAlertModal');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.id = 'aeroAlertModal';
            overlay.style.cssText = "position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.3); display: flex; justify-content: center; align-items: center; z-index: 9999;";

            const dialog = document.createElement('div');
            dialog.className = 'aero-gpa-alert-dialog';
            dialog.style.cssText = "background: linear-gradient(to bottom, #eaf3fb, #d4e6f3); border: 1px solid #88aaca; border-radius: 4px; box-shadow: 0 3px 12px rgba(22,59,99,0.35); width: 320px; font-family: Tahoma, Verdana, Arial, sans-serif; overflow: hidden;";
            
            dialog.innerHTML = `
                    <div style="background: linear-gradient(to bottom, #72c8ee, #287db7); color: white; padding: 6px 12px; font-size: 0.8rem; font-weight: bold; display: flex; justify-content: space-between; align-items: center;">
                    <span>📊 GPA Result</span>
                    <span style="cursor: pointer;" onclick="document.getElementById('aeroAlertModal').remove()">✕</span>
                </div>
                <div style="padding: 16px; font-size: 0.8rem; color: #263f52; text-align: center; line-height: 1.4;">
                    ${message}
                </div>
                <div style="padding: 8px 16px; background: #d7e8f5; display: flex; justify-content: center; border-top: 1px solid #a3bfd6;">
                    <button type="button" onclick="document.getElementById('aeroAlertModal').remove()" class="aero-button primary classic-dialog-button" style="padding: 4px 16px; font-size: 0.75rem; border-radius: 4px; cursor: pointer;">OK</button>
                </div>
            `;
            
            overlay.appendChild(dialog);
            document.body.appendChild(overlay);
        }

        window.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('gpaForm');
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const inputs = form.querySelectorAll('input');
                const prevGpaInput = inputs[0];
                const prevHoursInput = inputs[1];

                const prevGpa = parseFloat(prevGpaInput.value) || 0;
                const prevHours = parseFloat(prevHoursInput.value) || 0;

                let totalPoints = prevGpa * prevHours;
                let totalHours = prevHours;

                const rows = document.getElementById('coursesContainer').children;
                for (let i = 0; i < rows.length; i++) {
                    const hourInput = rows[i].querySelector('input[type="number"]');
                    const gradeSelect = rows[i].querySelector('select');

                    if (!gradeSelect.value) {
                        showAeroAlert(`Please select a grade for course #${i + 1}!`);
                        return;
                    }

                    const hours = parseFloat(hourInput.value) || 0;
                    const gradePoints = parseFloat(gradeSelect.value);

                    totalPoints += hours * gradePoints;
                    totalHours += hours;
                }

                if (totalHours === 0) {
                    showAeroAlert('Total hours cannot be zero.');
                    return;
                }

                const newGpa = totalPoints / totalHours;
                showAeroAlert(`Your Updated Cumulative GPA is: <b>${newGpa.toFixed(3)}</b><br><span style="font-size: 0.75rem; color: #555;">Total Passed/Registered Hours: ${totalHours}</span>`);
            });
        });
    </script>
</head>
<body>

    <!-- OS Window Container -->
    <div class="os-window app-window classic-window gpa-window">
        <!-- Window Title Bar -->
        <div class="window-header">
            <div class="window-title">
                <span class="os-icon">🌐</span> IT-Students-Hub.io - GPA Calculator
            </div>
            <div class="window-controls">
                <span class="control-btn minimize"></span>
                <span class="control-btn maximize"></span>
                <span class="control-btn close"></span>
            </div>
        </div>

        <!-- Browser Toolbar -->
        <div class="browser-toolbar">
            <div class="nav-arrows">
                <button class="arrow-btn">⬅</button>
                <button class="arrow-btn">➡</button>
            </div>
            <div class="address-bar">
                <span>🔒 http://it-students-hub.io/portal/pages/gpa.php</span>
            </div>
            <div class="browser-tools">
                <button class="tool-btn">🔍</button>
            </div>
        </div>

        <!-- Browser Tabs -->
        <div class="browser-tabs">
            <div class="tab"><a href="../index.php" style="text-decoration:none; color:inherit;">🏠 home</a></div>
            <div class="tab"><a href="../materials/materials.php" style="text-decoration:none; color:inherit;">📚 materials</a></div>
            <div class="tab active"><a href="gpa.php" style="text-decoration:none; color:inherit;">📊 gpa calculator</a></div>
            <div class="tab"><a href="games.php" style="text-decoration:none; color:inherit;">🎮 games</a></div>
            <div class="tab"><a href="chat.php" style="text-decoration:none; color:inherit;">💬 chat</a></div>
        </div>

        <!-- Main Window Content (Aero Style) -->
        <div class="window-body" style="padding: 14px; gap: 14px;">

            <!-- Main Content Area -->
            <div class="main-content" style="gap: 10px;">
                <div class="content-box welcome-box" style="padding: 12px 16px; display: flex; flex-direction: column;">
                    <h2 style="font-size: 1.15rem; margin-bottom: 5px; color: #01579b;">Cumulative GPA Calculator</h2>
                    <p style="font-size: 0.86rem; margin-bottom: 8px; color: #37474f;">
                        Enter your previous standing and current semester courses to calculate your updated cumulative GPA.
                    </p>
                    
                    <form id="gpaForm" style="display: flex; flex-direction: column; gap: 8px;">
                        <!-- Previous Standing Row -->
                        <div style="display: flex; gap: 8px; background: rgba(220, 238, 255, 0.4); padding: 6px 10px; border-radius: 6px; border: 1px solid #b3d7ff;">
                            <input type="text" inputmode="decimal" placeholder="Prev GPA (e.g. 3.56)" style="flex: 1; padding: 6px 8px; border: 1px solid #b3d7ff; border-radius: 6px; font-family: 'Varela Round', sans-serif; font-size: 0.8rem; background: rgba(255,255,255,0.9);">
                            <input type="number" min="0" placeholder="Prev Passed Hours" style="flex: 1; padding: 6px 8px; border: 1px solid #b3d7ff; border-radius: 6px; font-family: 'Varela Round', sans-serif; font-size: 0.8rem; background: rgba(255,255,255,0.9);">
                        </div>

                        <!-- Current Semester Courses Inputs -->
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <div class="course-heading-row" style="display: flex; justify-content: space-between; align-items: center;">
                                <label style="font-size: 0.8rem; font-weight: bold; color: #01579b;">Current Semester Courses</label>
                                <button type="button" onclick="addCourseRow()" class="aero-button primary add-course-btn" style="padding: 3px 8px; font-size: 0.7rem; border-radius: 4px; cursor: pointer; width: auto; flex-grow: 0;">➕ Add Course</button>
                            </div>

                            <div id="coursesContainer" style="display: flex; flex-direction: column; gap: 6px; max-height: 150px; overflow-y: auto; padding-right: 4px;">
                                <!-- Initial Course Row 1 -->
                                <div class="course-row" style="display: flex; align-items: center; gap: 8px;">
                                    <span class="course-number" style="font-size: 0.8rem; font-weight: bold; color: #0277bd; width: 18px;">1.</span>
                                    <input type="number" value="3" min="1" max="10" placeholder="Hours" style="width: 75px; padding: 6px 8px; border: 1px solid #b3d7ff; border-radius: 6px; font-family: 'Varela Round', sans-serif; font-size: 0.8rem; background: rgba(255,255,255,0.8);">
                                    <select class="grade-select" style="flex: 1; padding: 6px 8px; border: 1px solid #b3d7ff; border-radius: 6px; font-family: 'Varela Round', sans-serif; font-size: 0.8rem; background: rgba(255,255,255,0.8);">
                                        <option value="" disabled selected>Select Grade</option>
                                        <option value="4.00">A (4.00)</option>
                                        <option value="3.75">-A (3.75)</option>
                                        <option value="3.50">+B (3.50)</option>
                                        <option value="3.00">B (3.00)</option>
                                        <option value="2.75">-B (2.75)</option>
                                        <option value="2.50">+C (2.50)</option>
                                        <option value="2.00">C (2.00)</option>
                                        <option value="1.75">-C (1.75)</option>
                                        <option value="1.50">+D (1.50)</option>
                                        <option value="1.00">D (1.00)</option>
                                        <option value="0.00">-D / F (0.00)</option>
                                    </select>
                                    <button type="button" onclick="removeCourseRow(this)" class="aero-button secondary" style="padding: 6px 8px; font-size: 0.75rem; border-radius: 6px; cursor: pointer; width: auto; flex-grow: 0;">❌</button>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div style="display: flex; justify-content: flex-end; margin-top: 4px;">
                            <button type="submit" class="aero-button primary" style="padding: 5px 14px; font-size: 0.78rem; border-radius: 5px; cursor: pointer;">Calculate GPA</button>
                        </div>
                    </form>
                </div>
            </div>

        </div>

        <!-- Window Status Bar -->
        <div class="window-statusbar" style="padding: 4px 13px;">
            <span>(made with Frutiger Aero vibes & childhood passion) | Made by Jumana - All Rights Reserved</span>
            <span class="status-icons-right">📶 🔊 🪟</span>
        </div>
    </div>

</body>
</html>