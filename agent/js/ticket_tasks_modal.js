// Editable task rows for the ticket and recurring ticket add/edit modals, and the
// ticket template picker that pre-fills them.
//
// Rows submit as parallel tasks[] and task_estimates[] arrays, aligned by their
// order in the form, so removing a row removes both of its inputs together.
//
// Wrapped in an IIFE because a modal can be opened, closed and opened again in one
// page load, which re-runs this file - top-level const/let would throw on the
// second run. Delegated handlers are stored on document and replaced for the same
// reason, otherwise "Add Task" would add one row per time the modal was opened.

(function () {

    // Builds one task row. Values are assigned as properties rather than built into
    // markup, so a task name containing quotes or angle brackets needs no escaping.
    function buildTaskRow(taskName, taskEstimate) {

        const row = document.createElement("div");
        row.className = "row g-2 mb-2 ticket-task-row";

        const nameColumn = document.createElement("div");
        nameColumn.className = "col-7";

        const nameInput = document.createElement("input");
        nameInput.type = "text";
        nameInput.className = "form-control";
        nameInput.name = "tasks[]";
        nameInput.placeholder = "Task name";
        nameInput.maxLength = 255;
        nameInput.value = taskName || '';
        nameColumn.appendChild(nameInput);

        const estimateColumn = document.createElement("div");
        estimateColumn.className = "col-3";

        const estimateInput = document.createElement("input");
        estimateInput.type = "number";
        estimateInput.className = "form-control";
        estimateInput.name = "task_estimates[]";
        estimateInput.placeholder = "Mins";
        estimateInput.min = 0;
        estimateInput.value = taskEstimate || '';
        estimateColumn.appendChild(estimateInput);

        const removeColumn = document.createElement("div");
        removeColumn.className = "col-2";

        const removeButton = document.createElement("button");
        removeButton.type = "button";
        removeButton.className = "btn btn-secondary w-100 ticket-task-remove";
        removeButton.title = "Remove task";
        removeButton.innerHTML = '<i class="fa fa-fw fa-trash"></i>';
        removeColumn.appendChild(removeButton);

        row.appendChild(nameColumn);
        row.appendChild(estimateColumn);
        row.appendChild(removeColumn);

        return row;
    }

    function addTaskRow(taskName, taskEstimate) {

        const container = document.getElementById("ticketTasksContainer");

        if (!container) {
            return;
        }

        container.appendChild(buildTaskRow(taskName, taskEstimate));
    }

    // Replaces the whole list - used when a template is picked
    function setTaskRows(tasks) {

        const container = document.getElementById("ticketTasksContainer");

        if (!container) {
            return;
        }

        container.innerHTML = '';

        (tasks || []).forEach(task => {
            addTaskRow(task.name, task.estimate);
        });
    }

    // dataset always gives a string; jQuery used to auto-parse JSON here, so
    // parse it explicitly and still tolerate an already-parsed value
    function readTemplateTasks(option) {

        const tasks = option.dataset.tasks;

        if (!tasks) {
            return [];
        }

        if (typeof tasks === 'string') {
            try {
                return JSON.parse(tasks);
            } catch (error) {
                return [];
            }
        }

        return tasks;
    }

    function setWorkflowLock(versionId) {

        const hidden = document.getElementById('selectedRunbookVersion');
        if (!hidden) {
            return;
        }

        const locked = parseInt(versionId || 0, 10) > 0;
        hidden.value = locked ? parseInt(versionId, 10) : 0;
        document.getElementById('runbookWorkflowLock')?.classList.toggle('d-none', !locked);
        const addButton = document.getElementById('ticketTaskAdd');
        if (addButton) {
            addButton.disabled = locked;
            addButton.classList.toggle('d-none', locked);
        }
        document.querySelectorAll('#ticketTasksContainer input').forEach(input => {
            input.readOnly = locked;
        });
        document.querySelectorAll('#ticketTasksContainer .ticket-task-remove').forEach(button => {
            button.disabled = locked;
            button.classList.toggle('d-none', locked);
        });
    }

    function handleTicketTaskClick(event) {

        if (event.target.closest('#ticketTaskAdd')) {
            addTaskRow('', '');
            return;
        }

        const removeButton = event.target.closest('.ticket-task-remove');
        if (removeButton) {
            removeButton.closest('.ticket-task-row')?.remove();
        }
    }

    // Ticket template picker - fills in the subject, details and task rows
    function handleTicketTemplateChange(event) {

        if (!event.target.matches('#ticket_template_select')) {
            return;
        }

        const select = event.target;
        const option = select.options[select.selectedIndex];

        // Selecting "- No Template -" only unlinks the template - it must not wipe
        // whatever the user has already written or added
        if (!parseInt(option.value, 10)) {
            setWorkflowLock(0);
            return;
        }

        const templateSubject = option.dataset.subject || '';
        const templateDetails = option.dataset.details || '';

        document.getElementById('subjectInput').value = templateSubject;

        if (window.tinymce) {
            const editor = tinymce.get('detailsInput');
            if (editor) {
                editor.setContent(templateDetails);
            } else {
                document.getElementById('detailsInput').value = templateDetails;
            }
        } else {
            document.getElementById('detailsInput').value = templateDetails;
        }

        setTaskRows(readTemplateTasks(option));
        setWorkflowLock(option.dataset.runbookVersion || 0);
    }

    // AJAX modals can be opened more than once without reloading the page. Keep
    // the delegated listeners native and replace the previous instance whenever
    // this script runs so the modal does not depend on jQuery or double-bind.
    const handlerRegistryKey = '__n45TicketTasksModalHandlers';
    const previousHandlers = document[handlerRegistryKey];
    if (previousHandlers) {
        document.removeEventListener('click', previousHandlers.click);
        document.removeEventListener('change', previousHandlers.change);
    }

    document[handlerRegistryKey] = {
        click: handleTicketTaskClick,
        change: handleTicketTemplateChange
    };
    document.addEventListener('click', handleTicketTaskClick);
    document.addEventListener('change', handleTicketTemplateChange);

    const templateSelect = document.getElementById('ticket_template_select');
    if (templateSelect && templateSelect.selectedIndex >= 0) {
        setWorkflowLock(templateSelect.options[templateSelect.selectedIndex].dataset.runbookVersion || 0);
    }

})();
