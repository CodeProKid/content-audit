const reviewButton = document.querySelector( '.content-audit-reviewed-meta-box .button' );
reviewButton.addEventListener( 'click', unCheckAttributes );
reviewButton.addEventListener( 'click', sendAjaxRequest );

function unCheckAttributes(e) {
    e.preventDefault();
    const attributes = document.querySelectorAll( '#content_auditchecklist .selectit' );
    attributes.forEach( attribute => {
        const checkbox = attribute.querySelector( 'input[type="checkbox"]' );
        const termText = attribute.textContent;
        if ( termText.includes('review-due') || termText.includes('Outdated') ) {
            checkbox.checked = false;
        }
    } );
}

function sendAjaxRequest(e) {
    e.preventDefault();
    let postId = parseInt( this.dataset.id );
    const spinner = document.createElement('span');
    spinner.classList.add('spinner');
    spinner.classList.add('is-active');
    this.after(spinner);
    fetch(ajaxurl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8'},
        body: `action=content_audit_reset&post_id=${postId}`
    })
        .then(res => res.json())
        .then(response => {
            spinner.style.display = 'none';
            const text = document.createElement('p' );
            text.textContent = 'Successfully marked as reviewed. New expiration date is ' + response.expiration ;
            text.style.color = 'green';
            this.after(text);
            this.disabled = true;
        })
        .catch( err => {
            console.log(err);
        });
}