FROM python:3.8-alpine

RUN apk --no-cache add git
#For backports.zoneinfo
RUN apk --no-cache add build-base

WORKDIR /app

RUN ln -fs /usr/bin/python3.8 /usr/bin/python3
RUN ln -fs /usr/lib/python3/dist-packages/pip /usr/bin/pip
COPY requirements.txt start_server.py ./
RUN pip install --upgrade pip
RUN pip install -r requirements.txt

COPY server/ server/
COPY admin_crm/ admin_crm/
COPY migrations/ migrations/
#ADD characters/ characters/

CMD python3 ./start_server.py
