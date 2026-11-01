"""Create/reuse KioTrad's delivery project using gh; never change issue or PR state."""
import argparse

from project_api import GitHub, board_view, fields, items, mutation, projects

OWNER, REPOSITORY, TITLE = 'Kiyoonewton', 'kiotrad', 'KioTrad'
CARDS = {92: ('Ready to test', 'High', 'UI'), 93: ('Ready to test', 'High', 'Sync'), 94: ('Backlog', 'Medium', 'Sync')}
WORKFLOW = [('Backlog', 'GRAY'), ('Ready', 'BLUE'), ('In progress', 'YELLOW'),
            ('Review', 'PURPLE'), ('Ready to test', 'ORANGE'), ('Done', 'GREEN')]
FIELDS = {'Status': WORKFLOW, 'Priority': [('High', 'RED'), ('Medium', 'ORANGE'), ('Low', 'GRAY')],
          'Area': [('UI', 'BLUE'), ('Sync', 'GREEN'), ('Data', 'PURPLE')]}


def ensure_field(api, project, existing, name, choices, created):
    matches = [f for f in existing if f.get('name') == name]
    if len(matches) > 1 or (matches and 'options' not in matches[0]):
        raise ValueError(name + ' is ambiguous or is not a single-select field; existing fields preserved.')
    if not matches:
        return mutation(api, 'createProjectV2Field', 'CreateProjectV2FieldInput',
                        {'projectId': project, 'name': name, 'dataType': 'SINGLE_SELECT',
                         'singleSelectOptions': [{'name': label, 'color': color, 'description': ''} for label, color in choices]},
                        'projectV2Field{...on ProjectV2SingleSelectField{id name options{id name color description}}}')['projectV2Field']
    field = matches[0]
    original = field['options']
    if len({o['name'] for o in original}) != len(original):
        raise ValueError('Duplicate options in ' + name + '; review manually.')
    options = [dict(o) for o in original]
    # Only normalize GitHub's default Todo on a newly-created project. Existing boards retain custom states.
    if created and name == 'Status':
        for option in options:
            if option['name'] == 'Todo' and not any(o['name'] == 'Backlog' for o in options):
                option['name'] = 'Backlog'
    for label, color in choices:
        if not any(o['name'] == label for o in options):
            options.append({'name': label, 'color': color, 'description': ''})
    if created and name == 'Status':
        order = {label: index for index, (label, _) in enumerate(choices)}
        options.sort(key=lambda o: order.get(o['name'], len(order)))
    if options != original:
        return mutation(api, 'updateProjectV2Field', 'UpdateProjectV2FieldInput',
                        {'fieldId': field['id'], 'singleSelectOptions': options},
                        'projectV2Field{...on ProjectV2SingleSelectField{id name options{id name color description}}}')['projectV2Field']
    return field


def setup(api, project_number=None, progress=print):
    aliases = ' '.join(f'i{n}:issue(number:{n}){{id number state url}}' for n in CARDS)
    identity = api('query{viewer{id login} repository(owner:"Kiyoonewton",name:"kiotrad"){id ' + aliases + '}}')
    if identity['viewer']['login'].lower() != OWNER.lower() or not identity.get('repository'):
        raise ValueError('Authenticate gh as Kiyoonewton with access to Kiyoonewton/kiotrad; no changes made.')
    repo = identity['repository']
    if any(not repo.get('i' + str(n)) for n in CARDS):
        raise ValueError('A required issue is unavailable; no project changes made.')
    available = projects(api)
    matches = [p for p in available if p['number'] == project_number] if project_number is not None else [p for p in available if p['title'] == TITLE and not p['closed']]
    if len(matches) > 1 or (project_number is not None and not matches):
        raise ValueError('Project selection is ambiguous or unavailable. Use --project-number with an existing owned project.')
    created = not matches
    if created:
        progress('Creating private KioTrad project...')
        project = mutation(api, 'createProjectV2', 'CreateProjectV2Input',
                           {'ownerId': identity['viewer']['id'], 'repositoryId': repo['id'], 'title': TITLE},
                           'projectV2{id number title url closed}')['projectV2']
        # GitHub Projects defaults to private. Do not broaden visibility or change an existing board's visibility.
        mutation(api, 'updateProjectV2', 'UpdateProjectV2Input', {'projectId': project['id'], 'public': False}, 'projectV2{id}')
    else:
        project = matches[0]
        if project['closed']:
            raise ValueError('Selected project is closed; it was not reopened.')
        progress('Reusing existing KioTrad project...')
        mutation(api, 'linkProjectV2ToRepository', 'LinkProjectV2ToRepositoryInput',
                 {'projectId': project['id'], 'repositoryId': repo['id']}, 'repository{id}')
    progress('Preparing workflow fields...')
    existing = fields(api, project['id'])
    prepared = {name: ensure_field(api, project['id'], existing, name, choices, created) for name, choices in FIELDS.items()}
    existing_items = {r['content']['id']: r for r in items(api, project['id']) if r.get('content') and r['content'].get('id')}
    result = []
    for number, initial in CARDS.items():
        issue = repo['i' + str(number)]
        row = existing_items.get(issue['id'])
        if row is None:
            row = mutation(api, 'addProjectV2ItemById', 'AddProjectV2ItemByIdInput',
                           {'projectId': project['id'], 'contentId': issue['id']}, 'item{id}')['item']
            row['fieldValues'] = {'nodes': []}
        assigned = {v['field']['id']: v.get('optionId') for v in row['fieldValues']['nodes'] if v.get('field')}
        initial = ('Done', *initial[1:]) if issue['state'] == 'CLOSED' else initial
        for name, label in zip(FIELDS, initial):
            field = prepared[name]
            if assigned.get(field['id']):
                continue  # Reruns never reset a card someone has moved or reprioritized.
            option = next(o for o in field['options'] if o['name'] == label)
            mutation(api, 'updateProjectV2ItemFieldValue', 'UpdateProjectV2ItemFieldValueInput',
                     {'projectId': project['id'], 'itemId': row['id'], 'fieldId': field['id'],
                      'value': {'singleSelectOptionId': option['id']}}, 'projectV2Item{id}')
        progress('Task #' + str(number) + ' linked; existing field values preserved.')
        result.append({'issue': number, 'url': issue['url'], 'item_id': row['id']})
    progress('Preparing Delivery board view...')
    board_view(api, project['id'])
    return {'version': 'kiotrad-project-setup-1.0.0', 'project': project, 'items': result,
            'notice': 'Issue/PR states were not changed. Merged work stays Ready to test until verified.'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project-number', type=int, help='Reuse a specific project owned by Kiyoonewton')
    args = parser.parse_args()
    try:
        result = setup(GitHub(), args.project_number)
    except (RuntimeError, ValueError, KeyError, TypeError, AttributeError) as error:
        parser.exit(1, 'Project setup stopped: ' + str(error) + '\n')
    print('Project ready:', result['project']['url'])
    print('Open the Delivery board view. Existing card progress is preserved on reruns.')
