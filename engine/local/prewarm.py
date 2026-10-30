"""Prepare staging views before changing the active local database."""
import re


def prepare(sync, database, capital_minor):
    if not re.fullmatch(r'kiotrad_local_[0-9TZ]+', database):
        raise RuntimeError('Only an isolated local staging database may be prepared.')
    if type(capital_minor) is not int or capital_minor < 10000 or capital_minor > 1000000000:
        raise RuntimeError('Invalid simulation capital for staging preparation.')
    sync.compose('up', '-d', '--wait', 'redis', 'redis-locks')
    print('Preparing staging dashboard and Simulator; current local dashboard stays active...', flush=True)
    sync.compose('run', '--rm', '--no-deps', '-T',
                 '-e', 'MONGODB_DATABASE=' + database,
                 '-e', 'MONGODB_URI=mongodb://mongodb:27017/' + database,
                 '-e', 'SIMULATION_CAPITAL_NAIRA=' + str(capital_minor // 100),
                 'api', 'php', 'artisan', 'dashboard:watch', '--once')
    print('Staging dashboard and reusable evidence prepared.', flush=True)
